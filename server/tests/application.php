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

// The application role must come from the active licence, not a broader portal role.
final class FakeApplicationStatement extends PDOStatement {
    public function __construct(private array $row) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->row; }
}
final class FakeApplicationPDO extends PDO {
    public function __construct(public array $row) {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new FakeApplicationStatement($this->row); }
}
$_SESSION = ['application_license_id'=>1, 'application_device'=>str_repeat('a',64)];
$row = ['id'=>1, 'status'=>'active', 'expires_at'=>null, 'parent_expires_at'=>null,
    'organization_status'=>'active', 'parent_status'=>'active', 'assigned_user_id'=>10,
    'assigned_email'=>'test@example.test', 'license_role'=>'direction'];
$pdo = new FakeApplicationPDO($row);
$active = new ApplicationAccess($pdo);
$user = ['id'=>10,'organizationId'=>1,'email'=>'test@example.test','role'=>'manager'];
check($active->assertActiveDevice($user) === 'direction', 'Direction licence must remain read-only even for portal manager');
$pdo->row['license_role'] = 'user';
check($active->assertActiveDevice($user) === 'user', 'User licence must not inherit portal manager access');
$pdo->row['assigned_user_id'] = 11;
try { $active->assertActiveDevice($user); throw new LogicException('Reassigned licence accepted'); }
catch (RuntimeException $e) { check($e->getMessage() === 'LICENSE_INVALID', 'Unexpected reassignment error'); }
echo "Effective licence role and reassignment: OK\n";
