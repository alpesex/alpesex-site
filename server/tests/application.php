<?php
declare(strict_types=1);
require __DIR__ . '/../src/Application/ApplicationAccess.php';
require __DIR__ . '/../src/Application/ApplicationCipher.php';
use AlpesEx\Portal\Application\ApplicationAccess;
use AlpesEx\Portal\Application\ApplicationCipher;
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
// Access policy needs no database; instantiate without calling the PDO constructor.
$access = (new ReflectionClass(ApplicationAccess::class))->newInstanceWithoutConstructor();
$project = ['organization_id'=>1, 'owner_user_id'=>10, 'team_manager_id'=>20];
foreach ([
    [10,1,'user','editor'], [11,1,'user','none'], [20,1,'manager','editor'],
    [21,1,'manager','none'], [10,1,'direction','viewer'], [22,1,'direction','viewer'],
    [10,2,'manager','none'], [22,2,'direction','none']] as [$id,$org,$role,$expected]) {
    check($access->projectAccess(['id'=>$id,'organizationId'=>$org,'role'=>$role], $project) === $expected, "Incorrect access: $id/$org/$role");
}
$cipher = new ApplicationCipher(bin2hex(random_bytes(32)));
$encrypted = $cipher->encrypt('private project', 'project:one');
check($cipher->decrypt($encrypted, 'project:one') === 'private project', 'Roundtrip failed');
foreach (['project:two','document:one'] as $context) {
    try { $cipher->decrypt($encrypted, $context); throw new LogicException('Cross-context decryption accepted'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'APPLICATION_DATA_INVALID', 'Unexpected error'); }
}
$raw = base64_decode($encrypted);$raw[strlen($raw)-1] = chr(ord($raw[strlen($raw)-1]) ^ 1);
try { $cipher->decrypt(base64_encode($raw), 'project:one'); throw new LogicException('Tampered ciphertext accepted'); }
catch (RuntimeException $e) { check($e->getMessage() === 'APPLICATION_DATA_INVALID', 'Unexpected error'); }
echo "Access policy (8 cases), encryption, context isolation and tampering: OK\n";
