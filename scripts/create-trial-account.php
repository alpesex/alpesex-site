<?php
declare(strict_types=1);

// Administrative CLI only. No mail, payment, or modification of existing accounts.
function trialSign(array $claims, string $secret): string {
    $encode = static fn(string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    $body = $encode(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return $body . '.' . $encode(sodium_crypto_sign_detached('ASM-LICENSE-V1.' . $body, $secret));
}

function createTrial(PDO $pdo, string $email, array $template, string $secret, callable $verify, string $directory): string {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $email !== strtolower(trim($email))) throw new RuntimeException('Adresse invalide.');
    $expected = ['schema','type','id','plan','organizationId','company','authorityId','issuedAt','expiresAt'];
    if (array_diff(array_keys($template), $expected) || array_diff($expected, array_keys($template))
        || $template['schema'] !== 2 || $template['type'] !== 'master'
        || $template['authorityId'] !== 'alpesex-cpmp-production-2026') throw new RuntimeException('Format Master inattendu : arrêt sans création.');
    $check = $pdo->prepare('SELECT id FROM users WHERE email=?'); $check->execute([$email]);
    if ($check->fetchColumn()) throw new RuntimeException('Ce compte existe déjà : aucun changement effectué.');
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Dossier privé indisponible.');
    $suffix = bin2hex(random_bytes(12));
    $receipt = $directory . '/essai-' . $suffix . '.txt';
    $handle = fopen($receipt, 'x');
    if ($handle === false) throw new RuntimeException('Impossible de conserver les accès.');
    chmod($receipt, 0600);
    $committed = false;
    try {
        $pdo->beginTransaction();
        $now = time(); $expiry = $now + 30 * 86400;
        $issuedAt = $now * 1000; $expiresAt = $expiry * 1000;
        $sqlDate = gmdate('Y-m-d H:i:s', $now); $sqlExpiry = gmdate('Y-m-d H:i:s', $expiry);
        $company = "ALPES'Ex — Essai indépendant";
        // Sentinel records no online acceptance: this is an administrative test account, not a sale.
        $q = $pdo->prepare("INSERT INTO organizations (name,legal_form,country,billing_address_1,postal_code,city,billing_email,terms_accepted_at,status) VALUES (?,?,?,?,?,?,?,?,?)");
        $q->execute([$company,'Compte de test','France','Non renseignée — test','','',$email,'1970-01-01 00:00:00','active']);
        $org = (int)$pdo->lastInsertId();
        $password = bin2hex(random_bytes(16));
        $q = $pdo->prepare("INSERT INTO users (organization_id,email,password_hash,first_name,last_name,role,status,email_verified_at) VALUES (?,?,?,?,?,'manager','active',?)");
        $q->execute([$org,$email,password_hash($password,PASSWORD_DEFAULT),'Lucas','DAVID',$sqlDate]);
        $user = (int)$pdo->lastInsertId();
        $masterId = 'master-trial-' . $suffix;
        $licenseOrg = 'org-trial-' . $suffix;
        $master = ['schema'=>2,'type'=>'master','id'=>$masterId,'plan'=>$template['plan'],
            'organizationId'=>$licenseOrg,'company'=>$company,'authorityId'=>$template['authorityId'],
            'issuedAt'=>$issuedAt,'expiresAt'=>$expiresAt];
        $masterToken = trialSign($master,$secret); $verify($masterToken);
        $insert = $pdo->prepare("INSERT INTO organization_license_registry
            (organization_id,issuer_license_id,license_type,license_role,parent_master_license_id,token_hash,license_token,status,source,expires_at,created_by_user_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $insert->execute([$org,$masterId,'master',null,null,hash('sha256',$masterToken),$masterToken,'active','admin_trial',$sqlExpiry,$user]);
        foreach (['manager','manager','direction','user','user','user'] as $index=>$role) {
            $id = 'seat-trial-' . $suffix . '-' . ($index+1);
            $claims = ['schema'=>2,'type'=>'user','id'=>$id,'masterId'=>$masterId,
                'email'=>'unassigned-' . ($index+1) . '@stock.invalid','role'=>$role,'maxDevices'=>3,
                'organizationId'=>$licenseOrg,'company'=>$company,'authorityId'=>$template['authorityId'],
                'issuedAt'=>$issuedAt,'expiresAt'=>$expiresAt];
            $token = trialSign($claims,$secret); $verify($token);
            $insert->execute([$org,$id,'user',$role,$masterId,hash('sha256',$token),$token,'available','admin_trial',$sqlExpiry,$user]);
        }
        $text = "COMPTE D’ESSAI CPMP-ASM\n\nConnexion : https://alpes-ex.fr/compte/\nProfil : Gestionnaire\nE-mail : $email\nMot de passe : $password\nFin des licences (UTC) : $sqlExpiry\n\nLICENCE MASTER IT-ASM\n$masterToken\n\nStock : 2 Manager, 1 Direction, 3 Utilisateur.\nAttribuer les licences depuis Mon compte. Aucune licence individuelle n’est attribuée au gestionnaire à ce stade.\nCompte créé administrativement pour tests ; coordonnées de facturation non renseignées.\n";
        if (fwrite($handle,$text) !== strlen($text) || !fflush($handle)) throw new RuntimeException('Écriture des accès incomplète.');
        fclose($handle); $handle = null;
        $pdo->commit(); $committed = true;
        return $receipt;
    } finally {
        if (is_resource($handle)) fclose($handle);
        if (!$committed) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (is_file($receipt)) unlink($receipt);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    umask(0077);
    try {
        $email = strtolower(trim($argv[1] ?? ''));
        $services = require '/home/www/app/bootstrap.php';
        $pdo = AlpesEx\Portal\Database::connect($services['config']);
        $verifier = new AlpesEx\Portal\Licensing\LicenseTokenVerifier();
        $token = $pdo->query("SELECT license_token FROM organization_license_registry WHERE license_type='master' AND status='active' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) ORDER BY id DESC LIMIT 1")->fetchColumn();
        if (!is_string($token)) throw new RuntimeException('Aucune Master valide de référence.');
        $template = $verifier->verify($token);
        $pem = file_get_contents('/home/www/private/licenses/issuer-private.pem');
        if (!is_string($pem) || !preg_match('/-----BEGIN PRIVATE KEY-----\s*(.*?)\s*-----END PRIVATE KEY-----/s',$pem,$m)) throw new RuntimeException('Clé de signature illisible.');
        $der = base64_decode(preg_replace('/\s+/','',$m[1]),true);
        if (!is_string($der) || !str_contains($der,"\x06\x03\x2B\x65\x70")) throw new RuntimeException('Clé Ed25519 requise.');
        $pos = strrpos($der,"\x04\x20");
        $seed = $pos === false ? '' : substr($der,$pos+2,32);
        if (strlen($seed)!==32) throw new RuntimeException('Clé de signature incompatible.');
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
        $receipt = createTrial($pdo,$email,$template,$secret,[$verifier,'verify'],'/home/www/private/trials');
        sodium_memzero($secret);
        echo "COMPTE_ESSAI_CREE : $email\n1 Master active ; stock : 2 Manager, 1 Direction, 3 Utilisateur.\nAccès et Master conservés dans : $receipt\nPour les afficher sur votre terminal :\ncat '$receipt'\n";
    } catch (Throwable $e) {
        fwrite(STDERR,"ARRÊT : " . $e->getMessage() . "\n"); exit(1);
    }
}
