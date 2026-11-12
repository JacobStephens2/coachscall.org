<?php
/**
 * Run: php tests/admin_account_lock_test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CC_ADMIN_FUNCTIONS_ONLY', true);
require dirname(__DIR__) . '/admin/index.php';

$mode = $argv[1] ?? 'suite';

if ($mode === 'pending-add' || $mode === 'pending-add-signal') {
    if ($mode === 'pending-add-signal') {
        file_put_contents($argv[5], 'ready');
    }
    $ok = pending_add($argv[2], $argv[3], [
        'email' => $argv[4],
        'expires' => time() + 3600,
    ]);
    exit($ok ? 0 : 1);
}

if ($mode === 'add-editor' || $mode === 'add-editor-signal') {
    if ($mode === 'add-editor-signal') {
        file_put_contents($argv[6], 'ready');
    }
    $created = $mode === 'add-editor-signal' ? 2 : 1;
    $ok = update_accounts($argv[2], $argv[3], function (array $users) use ($argv, $created): array {
        $users[$argv[4]] = ['hash' => $argv[5], 'created' => $created];
        return $users;
    });
    exit($ok ? 0 : 1);
}

if ($mode !== 'suite') {
    fwrite(STDERR, "Unknown mode {$mode}\n");
    exit(2);
}

function check(bool $cond, string $message): void
{
    if ($cond) {
        return;
    }
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/cc-admin-lock-' . bin2hex(random_bytes(4));
    check(mkdir($dir, 0700), 'create temp dir');
    return $dir;
}

function rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            rm_tree($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

/**
 * @param list<string> $command
 * @return array{proc: resource, err: string}
 */
function start_php(array $command): array
{
    $err = tempnam(sys_get_temp_dir(), 'cc-err-');
    check($err !== false, 'stderr temp');
    $proc = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $err, 'w']],
        $pipes
    );
    check(is_resource($proc), 'spawn ' . implode(' ', $command));
    fclose($pipes[0]);
    return ['proc' => $proc, 'err' => $err];
}

function finish_php(array $child): void
{
    $status = proc_get_status($child['proc']);
    $deadline = time() + 20;
    while ($status['running'] && time() < $deadline) {
        usleep(20000);
        $status = proc_get_status($child['proc']);
    }
    if ($status['running']) {
        proc_terminate($child['proc']);
        proc_close($child['proc']);
        $err = (string) file_get_contents($child['err']);
        @unlink($child['err']);
        fwrite(STDERR, "FAIL: child timed out\n{$err}\n");
        exit(1);
    }
    $code = proc_close($child['proc']);
    $err = (string) file_get_contents($child['err']);
    @unlink($child['err']);
    if ($code !== 0) {
        fwrite(STDERR, "FAIL: child exit {$code}\n{$err}\n");
        exit(1);
    }
}

/**
 * The child must already be waiting on $path's lock. Fail if it finishes
 * while that lock is still held, then run $whileLocked before the caller
 * releases the lock.
 *
 * @param array{proc: resource, err: string} $child
 */
function assert_blocked(array $child, string $ready, string $notStarted, string $finishedWhileLocked, callable $whileLocked): void
{
    $deadline = microtime(true) + 5;
    while (!is_file($ready) && microtime(true) < $deadline) {
        usleep(1000);
    }
    check(is_file($ready), $notStarted);
    usleep(300000);
    $status = proc_get_status($child['proc']);
    check($status['running'], $finishedWhileLocked);
    $whileLocked();
}

function hold_lock(string $path)
{
    $fh = fopen(sibling_lock_path($path), 'c');
    check(is_resource($fh) && flock($fh, LOCK_EX), 'take exclusive lock');
    return $fh;
}

/** @param resource $fh */
function release_lock($fh): void
{
    flock($fh, LOCK_UN);
    fclose($fh);
}

$testFile = __FILE__;

function token_add_waits_for_the_lock(string $testFile): void
{
    $dir = temp_dir();
    $tokenFile = $dir . '/reset.json';
    file_put_contents($tokenFile, '{}');
    $lock = hold_lock($tokenFile);
    $ready = $tokenFile . '.ready';
    $child = start_php([
        PHP_BINARY, $testFile, 'pending-add-signal', $tokenFile, 'token-b', 'b@example.com', $ready,
    ]);
    assert_blocked(
        $child,
        $ready,
        'token child did not start',
        'pending_add finished while the token file lock was held',
        function () use ($tokenFile): void {
            $kept = hash('sha256', 'token-c');
            $json = json_encode([
                $kept => ['email' => 'c@example.com', 'expires' => time() + 3600],
            ]);
            check($json !== false && atomic_write($tokenFile, $json), 'write token while lock held');
        }
    );
    release_lock($lock);
    finish_php($child);
    check(pending_lookup($tokenFile, 'token-b') !== null, 'token added after the lock was released is missing');
    check(pending_lookup($tokenFile, 'token-c') !== null, 'token written while the adder waited was lost');
    check(pending_lookup($tokenFile, 'token-a') === null, 'adder kept a map it read before the lock');
    rm_tree($dir);
}

function concurrent_token_adds_all_remain(string $testFile): void
{
    $dir = temp_dir();
    $path = $dir . '/invites.json';
    file_put_contents($path, '{}');
    $n = 30;
    $children = [];
    for ($i = 0; $i < $n; $i++) {
        $children[] = start_php([
            PHP_BINARY, $testFile, 'pending-add', $path, 'token-' . $i, 'u' . $i . '@example.com',
        ]);
    }
    foreach ($children as $child) {
        finish_php($child);
    }
    for ($i = 0; $i < $n; $i++) {
        $entry = pending_lookup($path, 'token-' . $i);
        check($entry !== null && $entry['email'] === 'u' . $i . '@example.com', 'lost token-' . $i);
    }
    rm_tree($dir);
}

function token_add_prunes_expired_and_remove_keeps_the_rest(): void
{
    $dir = temp_dir();
    $path = $dir . '/reset.json';
    $expired = hash('sha256', 'old-token');
    $keep = hash('sha256', 'keep-token');
    $json = json_encode([
        $expired => ['email' => 'old@example.com', 'expires' => time() - 10],
        $keep => ['email' => 'keep@example.com', 'expires' => time() + 3600],
    ]);
    check($json !== false && atomic_write($path, $json), 'seed tokens');
    check(pending_add($path, 'new-token', ['email' => 'new@example.com', 'expires' => time() + 3600]), 'add token');
    check(pending_lookup($path, 'old-token') === null, 'expired token was kept');
    check(pending_lookup($path, 'keep-token')['email'] === 'keep@example.com', 'live token was dropped by add');
    check(pending_lookup($path, 'new-token')['email'] === 'new@example.com', 'new token missing');
    pending_remove($path, 'keep-token');
    check(pending_lookup($path, 'keep-token') === null, 'removed token still present');
    check(pending_lookup($path, 'new-token')['email'] === 'new@example.com', 'remove dropped another token');
    rm_tree($dir);
}

function account_add_waits_for_the_lock(string $testFile): void
{
    $dir = temp_dir();
    $users = $dir . '/admin_users.php';
    $old = $dir . '/admin_credentials.php';
    check(save_users($users, [
        'alice@example.com' => ['hash' => 'alice-hash', 'created' => 1],
    ]), 'seed alice');
    $lock = hold_lock($users);
    $ready = $users . '.ready';
    $child = start_php([
        PHP_BINARY, $testFile, 'add-editor-signal', $users, $old, 'bob@example.com', 'bob-hash', $ready,
    ]);
    assert_blocked(
        $child,
        $ready,
        'account child did not start',
        'account update finished while the account file lock was held',
        function () use ($users): void {
            check(save_users($users, [
                'alice@example.com' => ['hash' => 'alice-hash', 'created' => 1],
                'carol@example.com' => ['hash' => 'carol-hash', 'created' => 3],
            ]), 'write carol while lock held');
        }
    );
    release_lock($lock);
    finish_php($child);
    $loaded = load_users($users, $old);
    check(($loaded['alice@example.com']['hash'] ?? '') === 'alice-hash', 'alice was lost or changed');
    check(($loaded['carol@example.com']['hash'] ?? '') === 'carol-hash', 'editor written while the adder waited was lost');
    check(($loaded['bob@example.com']['hash'] ?? '') === 'bob-hash', 'editor added after the lock was released is missing');
    check(count($loaded) === 3, 'account update persisted a map read before the lock');
    rm_tree($dir);
}

function concurrent_editor_adds_all_remain(string $testFile): void
{
    $dir = temp_dir();
    $users = $dir . '/admin_users.php';
    $old = $dir . '/admin_credentials.php';
    $n = 20;
    $children = [];
    for ($i = 0; $i < $n; $i++) {
        $children[] = start_php([
            PHP_BINARY, $testFile, 'add-editor', $users, $old, 'ed' . $i . '@example.com', 'hash-' . $i,
        ]);
    }
    foreach ($children as $child) {
        finish_php($child);
    }
    $loaded = load_users($users, $old);
    check(count($loaded) === $n, 'kept ' . count($loaded) . " of {$n} editors");
    for ($i = 0; $i < $n; $i++) {
        check(($loaded['ed' . $i . '@example.com']['hash'] ?? '') === 'hash-' . $i, 'lost ed' . $i);
    }
    rm_tree($dir);
}

function first_editor_setup_does_not_replace_an_existing_account(): void
{
    $dir = temp_dir();
    $users = $dir . '/admin_users.php';
    $old = $dir . '/admin_credentials.php';
    check(setup_first_editor($users, $old, 'Ada@Example.com', 'first-password'), 'setup first editor');
    $loaded = load_users($users, $old);
    check(count($loaded) === 1, 'setup wrote more than the new editor');
    check(isset($loaded['ada@example.com']), 'email was not stored in lowercase');
    check(password_verify('first-password', $loaded['ada@example.com']['hash']), 'first password does not verify');
    check(setup_first_editor($users, $old, 'other@example.com', 'second-password') === false, 'second setup replaced the first editor');
    $again = load_users($users, $old);
    check($again === $loaded, 'failed setup changed the account file');
    rm_tree($dir);
}

function removal_password_reset_and_invite_change_only_their_entry(): void
{
    $dir = temp_dir();
    $users = $dir . '/admin_users.php';
    $old = $dir . '/admin_credentials.php';
    $reset = $dir . '/reset.json';
    $invites = $dir . '/invites.json';
    $alice = password_hash('alice-secret', PASSWORD_DEFAULT);
    $bob = password_hash('bob-secret', PASSWORD_DEFAULT);
    check(save_users($users, [
        'alice@example.com' => ['hash' => $alice, 'created' => 111],
        'bob@example.com' => ['hash' => $bob, 'created' => 222],
        'carol@example.com' => ['hash' => 'carol-hash', 'created' => 333],
    ]), 'seed editors');

    check(remove_editor_account($users, $old, 'alice@example.com', 'alice@example.com') === false, 'removed the signed-in editor');
    check(remove_editor_account($users, $old, 'alice@example.com', 'bob@example.com'), 'remove bob');
    $loaded = load_users($users, $old);
    check(!isset($loaded['bob@example.com']), 'bob still present');
    check($loaded['alice@example.com']['hash'] === $alice, 'remove changed alice');
    check($loaded['carol@example.com']['hash'] === 'carol-hash', 'remove dropped carol');
    check(remove_editor_account($users, $old, 'alice@example.com', 'carol@example.com'), 'remove carol');
    check(array_keys(load_users($users, $old)) === ['alice@example.com'], 'last other editor was not removed');
    check(remove_editor_account($users, $old, 'alice@example.com', 'alice@example.com') === false, 'removed the only editor');
    check(isset(load_users($users, $old)['alice@example.com']), 'only editor is gone');

    check(change_editor_password($users, $old, 'alice@example.com', 'alice-secret', 'alice-new-secret'), 'change password');
    $loaded = load_users($users, $old);
    check(password_verify('alice-new-secret', $loaded['alice@example.com']['hash']), 'new password does not verify');
    check($loaded['alice@example.com']['created'] === 111, 'password change replaced the account record');
    check(count($loaded) === 1, 'password change added or dropped an editor');
    $hashAfter = $loaded['alice@example.com']['hash'];
    check(change_editor_password($users, $old, 'alice@example.com', 'alice-secret', 'another-secret-1') === false, 'old password still worked');
    check(load_users($users, $old)['alice@example.com']['hash'] === $hashAfter, 'rejected password change was saved');

    check(save_users($users, [
        'alice@example.com' => ['hash' => $hashAfter, 'created' => 111],
        'bob@example.com' => ['hash' => $bob, 'created' => 222],
    ]), 'restore bob');
    check(pending_add($reset, 'reset-alice', ['email' => 'alice@example.com', 'expires' => time() + 3600]), 'add alice reset');
    check(pending_add($reset, 'reset-bob', ['email' => 'bob@example.com', 'expires' => time() + 3600]), 'add bob reset');
    $expired = json_encode([
        hash('sha256', 'reset-alice') => ['email' => 'alice@example.com', 'expires' => time() + 3600],
        hash('sha256', 'reset-bob') => ['email' => 'bob@example.com', 'expires' => time() + 3600],
        hash('sha256', 'reset-old') => ['email' => 'alice@example.com', 'expires' => time() - 5],
    ]);
    check($expired !== false && atomic_write($reset, $expired), 'seed expired reset');
    check(save_reset_password($users, $old, $reset, 'reset-old', 'expired-secret-1') === false, 'expired reset was accepted');
    check(save_reset_password($users, $old, $reset, 'reset-alice', 'reset-secret-1'), 'save reset');
    $loaded = load_users($users, $old);
    check(password_verify('reset-secret-1', $loaded['alice@example.com']['hash']), 'reset password does not verify');
    check($loaded['alice@example.com']['created'] === 111, 'reset replaced the account record');
    check($loaded['bob@example.com']['hash'] === $bob, 'reset changed bob');
    check(pending_lookup($reset, 'reset-alice') === null, 'used reset token remains');
    check(pending_lookup($reset, 'reset-bob')['email'] === 'bob@example.com', 'reset removed another token');
    check(pending_lookup($reset, 'reset-old') === null, 'expired token still looks valid');

    check(pending_add($invites, 'invite-bob', [
        'email' => 'bob@example.com', 'expires' => time() + 3600, 'by' => 'alice@example.com',
    ]), 'invite bob');
    check(pending_add($invites, 'invite-carol', [
        'email' => 'carol@example.com', 'expires' => time() + 3600, 'by' => 'alice@example.com',
    ]), 'invite carol');
    $accepted = accept_editor_invite($users, $old, $invites, 'invite-bob', 'bob-invited-secret');
    check($accepted === 'bob@example.com', 'invite acceptance did not return bob');
    $loaded = load_users($users, $old);
    check(password_verify('bob-invited-secret', $loaded['bob@example.com']['hash']), 'invited password does not verify');
    check(password_verify('reset-secret-1', $loaded['alice@example.com']['hash']), 'invite changed alice');
    check($loaded['alice@example.com']['created'] === 111, 'invite rewrote alice');
    check(pending_lookup($invites, 'invite-bob') === null, 'used invite remains');
    check(pending_lookup($invites, 'invite-carol')['email'] === 'carol@example.com', 'invite acceptance removed another invite');
    rm_tree($dir);
}

function legacy_account_file_is_migrated_once(): void
{
    $dir = temp_dir();
    $users = $dir . '/admin_users.php';
    $old = $dir . '/admin_credentials.php';
    $php = "<?php\nreturn " . var_export(['email' => 'Ada@Example.com', 'hash' => 'legacy-hash'], true) . ";\n";
    check(atomic_write($old, $php), 'write legacy file');
    $loaded = load_users($users, $old);
    check(isset($loaded['ada@example.com']), 'legacy email was not migrated');
    check($loaded['ada@example.com']['hash'] === 'legacy-hash', 'legacy hash changed');
    check(is_readable($users), 'migration did not write the account file');
    unlink($old);
    $again = load_users($users, $old);
    check($again === $loaded, 'second load changed the migrated accounts');
    rm_tree($dir);
}

token_add_waits_for_the_lock($testFile);
concurrent_token_adds_all_remain($testFile);
token_add_prunes_expired_and_remove_keeps_the_rest();
account_add_waits_for_the_lock($testFile);
concurrent_editor_adds_all_remain($testFile);
first_editor_setup_does_not_replace_an_existing_account();
removal_password_reset_and_invite_change_only_their_entry();
legacy_account_file_is_migrated_once();

echo "ok\n";
