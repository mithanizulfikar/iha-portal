<?php
declare(strict_types=1);

/**
 * Static privacy scan (no database, no server needed):   php tests/privacy_scan.php
 * Everything a student's browser can receive (student, email, login/registration and QR templates, plus the
 * JavaScript and CSS files) must contain no eligibility wording. The public landing/privacy/terms pages are not
 * scanned: they deliberately publish the general criteria, never an individual's result.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
}
function files(string $dir, array $exts): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (in_array(strtolower($f->getExtension()), $exts, true)) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
}

$forbidden = '/eligib|pending[ _]review|\\belig\\b/i';

echo "Student-facing templates and assets\n";
$targets = array_merge(
    files($root . '/templates/student', ['php']),
    files($root . '/templates/emails', ['php']),
    files($root . '/templates/auth', ['php']),
    files($root . '/templates/qr', ['php']),
    files($root . '/public/assets/js', ['js']),
    files($root . '/public/assets/css', ['css'])
);
check('there are files to scan', count($targets) > 20);
foreach ($targets as $file) {
    $text = (string) file_get_contents($file);
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file);
    check("no eligibility wording in {$rel}", !preg_match($forbidden, $text, $m));
}

echo "Code boundaries\n";
$uses = [];
foreach (files($root . '/src/Student', ['php']) as $f) {
    if (preg_match('/Iha\\\\Eligibility|EligibilityService|EligibilityEngine/', (string) file_get_contents($f))) {
        $uses[] = basename($f);
    }
}
check('only SubmissionService (which records the result at submission) touches the eligibility code in src/Student', $uses === ['SubmissionService.php']);
check('the student controllers never read the eligibility tables', !preg_grep('/application_eligibility|eligibility_results|eligibility_overrides/', array_map(
    static fn($f) => (string) file_get_contents($f),
    array_filter(files($root . '/src/Student', ['php']), static fn($f) => basename($f) !== 'SubmissionService.php')
)));
check('the student dashboard code (PageController) does not use the eligibility code', !preg_match('/Iha\\\\Eligibility|application_eligibility|eligibility_results/', (string) file_get_contents($root . '/src/Web/PageController.php')));
$qr = (string) file_get_contents($root . '/src/QR/QrService.php');
$start = strpos($qr, 'public function verify(');
$end = strpos($qr, '// ---- internals', (int) $start);
$verify = ($start !== false && $end !== false) ? substr($qr, $start, $end - $start) : '';
check('QrService::verify (the public page) was found and is not empty', strlen($verify) > 500);
check('the public verification query never touches eligibility data', !preg_match('/eligib/i', $verify));
$ctrl = (string) file_get_contents($root . '/src/QR/QrController.php');
check('the public verification controller does not use admin permissions', !str_contains($ctrl, 'Permissions'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
