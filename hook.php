<?php

$fp = fopen('hook.log', 'a+');

$log = function ($msg) use ($fp) {
    $now = new DateTime();
    fputs($fp, sprintf("[%s] %s", $now->format('Y-m-d H:i:s'), $msg . PHP_EOL));
};

$configFile = __DIR__ . '/config.ini';
if (!is_file($configFile)) {
    $log('Missing config file.');
    return;
}
$config     = parse_ini_file($configFile, true);
$recipients = explode(',', $config['misc']['notify']);
$hostname   = exec('hostname');

$mail = function ($subject, $body) use ($recipients) {
    foreach ($recipients as $email) {
        mail($email, $subject, $body);
    }
};

if (!isset($_POST['payload'])) {
    $log('No payload provided.');
    return;
}
$json = $_POST['payload'];
if (!$payload = json_decode($json)) {
    $log('Could not parse json: ' . $json);
    return;
}

// Only work on master branch
// TODO: enable support for different branches
$ref = $payload->ref;
if ($ref !== 'refs/heads/master') {
    $parts  = explode('/', $ref);
    $branch = array_pop($parts);
    $log(sprintf('Ignoring changes on branch "%s" …', $branch));
    return;
}

$repo = $payload->repository->name;
if (isset($config['repos'][$repo])) {
    $log(sprintf('Update for "%s" requested …', $repo));
} else {
    $log(sprintf('Unregistered repository "%s".', $repo));
    return;
}

$wd = $config['repos'][$repo];

$hr = str_repeat('=', 72);

// Add messages
$msg = 'Good news everyone!' . PHP_EOL .
    sprintf('Our machine "%s" has been updated to the latest and greatest version!', $hostname) . PHP_EOL . PHP_EOL;

$msg .= 'Changeset' . PHP_EOL . $hr . PHP_EOL;

foreach ($payload->commits as $commit) {
    $msg .= PHP_EOL . $commit->message . PHP_EOL;
    $msg .= sprintf('by @%s', $commit->author->username) . PHP_EOL;
    $msg .= $commit->url . PHP_EOL;
}

// Update by pulling
$updateStatus = sprintf('Updating "%s" at "%s" …', $repo, $wd);
$msg .= PHP_EOL . PHP_EOL . $updateStatus . PHP_EOL . $hr . PHP_EOL;
$log($updateStatus);
putenv('GIT_SSH=/kunden/265075_88045/bin/ssh-www.sh');
exec(sprintf('cd %s; git pull 2>&1', escapeshellarg($wd)), $pullOutput, $return);
exec(sprintf('cd %s; git status 2>&1', escapeshellarg($wd)), $statusOutput);
$msg .= PHP_EOL . join(PHP_EOL, $pullOutput) . PHP_EOL . PHP_EOL;
$msg .= PHP_EOL . 'Output of git status follows …' . PHP_EOL;
$msg .= PHP_EOL . join(PHP_EOL, $statusOutput);
if ($return !== 0) {
    $log(sprintf("Update failed with exit code %d: %s", $return, PHP_EOL . $msg));
    $mail(sprintf('GitHub Hook: PULL FAILED on "%s" for repository "%s".', $hostname, $repo), $msg);
    return;
}

// Run deployscript if available
$msg .= PHP_EOL . PHP_EOL . "Executing deploy script" . PHP_EOL . $hr . PHP_EOL;
$deployScript = sprintf('%s/deploy/%s.sh', $wd, $hostname);
if (is_file($deployScript)) {
    exec($deployScript . '  2>&1', $deployOutput, $deployReturn);
    if ($deployReturn !== 0) {
        $deployMsg = join(PHP_EOL, $deployOutput);
        $log(sprintf('Deploy script "%s" failed with exit code %d: %s', $deployScript, $return, PHP_EOL . $deployMsg));
        $mail(sprintf('GitHub Hook: DEPLOY FAILED on "%s" for repository "%s".', $hostname, $repo), $deployMsg);
        return;
    }
} else {
    $noDeployLog = sprintf('Note: No deploy script found at "%s".', $deployScript);
    $msg .= PHP_EOL . $noDeployLog;
    $log($noDeployLog);
}

$log(sprintf('Updated "%s".', $repo));
$mail(sprintf('GitHub Hook run on "%s" for repository "%s".', $hostname, $repo), $msg);
