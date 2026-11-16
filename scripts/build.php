<?php
/**
 * Release Build Script for DLE Social AutoPost Plugin
 *
 * Usage: php scripts/build.php [version]
 */

$repoDir  = dirname(__DIR__);
$xmlFile  = $repoDir . '/plugin.xml';
$distDir  = $repoDir . '/dist';
$docsDir  = $repoDir . '/docs';
$distJson = $distDir . '/update.json';
$docsJson = $docsDir . '/update.json';

echo "=== DLE Social AutoPost Plugin Release Builder ===\n\n";

// 1. Determine target version
$targetVersion = null;
if (!empty($argv[1])) {
    $targetVersion = ltrim(trim($argv[1]), 'v');
} else {
    if (file_exists($xmlFile)) {
        $xml = @simplexml_load_file($xmlFile);
        if ($xml && isset($xml->version)) {
            $targetVersion = (string)$xml->version;
        }
    }
}

if (!$targetVersion || !preg_match('/^[0-9]+\.[0-9]+(\.[0-9]+)?(-[a-zA-Z0-9.]+)?$/', $targetVersion)) {
    fwrite(STDERR, "Error: Invalid version format '{$targetVersion}'. Expected semantic version like 1.1.0\n");
    exit(1);
}

echo "Target release version: {$targetVersion}\n";

// 2. Validate PHP files syntax
echo "\nValidating PHP files syntax...\n";
$dirsToScan = [$repoDir . '/engine', $repoDir . '/cron'];
$phpFiles = [];

foreach ($dirsToScan as $dir) {
    if (!is_dir($dir)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }
}

foreach ($phpFiles as $file) {
    $rel = str_replace($repoDir . '/', '', $file);
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Syntax error in {$rel}:\n" . implode("\n", $out) . "\n");
        exit(1);
    }
    echo "  [OK] {$rel}\n";
}

// 3. Validate XML manifest
echo "\nValidating XML manifest structure...\n";
$xml = @simplexml_load_file($xmlFile);
if (!$xml) {
    fwrite(STDERR, "Error: plugin.xml is not valid XML!\n");
    exit(1);
}

$requiredXmlTags = ['name', 'description', 'icon', 'version', 'dleversion', 'upgradeurl', 'filedelete', 'filelist', 'mysqlinstall', 'mysqluninstall', 'phpuninstall'];
foreach ($requiredXmlTags as $tag) {
    if (!isset($xml->$tag)) {
        fwrite(STDERR, "Error: Missing required XML tag <{$tag}> in plugin.xml!\n");
        exit(1);
    }
}
echo "  [OK] plugin.xml has all mandatory tags\n";

// 4. Validate admin icon
echo "\nValidating admin brand icon...\n";
$iconFile = $repoDir . '/engine/skins/images/autopost.png';
if (!file_exists($iconFile)) {
    fwrite(STDERR, "Error: Missing admin icon at {$iconFile}!\n");
    exit(1);
}
$imgInfo = @getimagesize($iconFile);
if (!$imgInfo || $imgInfo[2] !== IMAGETYPE_PNG) {
    fwrite(STDERR, "Error: Admin icon {$iconFile} is not a valid PNG image!\n");
    exit(1);
}
echo "  [OK] Admin icon validated ({$imgInfo[0]}x{$imgInfo[1]} PNG, " . round(filesize($iconFile) / 1024, 1) . " KB)\n";

// 5. Synchronize version in XML manifest
echo "\nSynchronizing version {$targetVersion}...\n";
$xmlContent = file_get_contents($xmlFile);
$xmlContent = preg_replace('/<version>[^<]+<\/version>/', "<version>{$targetVersion}</version>", $xmlContent, 1);
file_put_contents($xmlFile, $xmlContent);
echo "  [OK] Updated plugin.xml\n";

// 6. Synchronize update.json manifests
$updateData = [
    'version'      => $targetVersion,
    'dle'          => (string)$xml->dleversion,
    'url'          => 'https://shlyahten.github.io/dle-social-autopost/dle-social-autopost.xml',
    'download_zip' => "https://shlyahten.github.io/dle-social-autopost/dle-social-autopost-v{$targetVersion}.zip",
    'description'  => "Автопостинг новостей в VK и Telegram v{$targetVersion}: поддержка DLE 21.0, кастомный Endpoint API, встроенный тест доступности, обновленный логотип и чистое удаление файлов.",
];

$jsonFormatted = json_encode($updateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents($distJson, $jsonFormatted);
file_put_contents($docsJson, $jsonFormatted);
echo "  [OK] Updated dist/update.json and docs/update.json\n";

// 7. Prepare dist directory and copy standalone XML
if (!is_dir($distDir)) {
    mkdir($distDir, 0755, true);
}
if (!is_dir($docsDir)) {
    mkdir($docsDir, 0755, true);
}

copy($xmlFile, $distDir . '/dle-social-autopost.xml');
copy($xmlFile, $docsDir . '/dle-social-autopost.xml');
echo "  [OK] Copied dle-social-autopost.xml to dist/ and docs/\n";

// 8. Build ZIP distribution package
echo "\nBuilding ZIP distribution package...\n";
$versionedZip = $distDir . "/dle-social-autopost-v{$targetVersion}.zip";
$latestZip    = $distDir . "/dle-social-autopost.zip";
$docsVerZip   = $docsDir . "/dle-social-autopost-v{$targetVersion}.zip";
$docsLatestZip= $docsDir . "/dle-social-autopost.zip";

@unlink($versionedZip);
@unlink($latestZip);

// Create temp build staging directory
$tempBuild = sys_get_temp_dir() . '/autopost-build-' . uniqid();
mkdir($tempBuild, 0755, true);

function copyTree($src, $dst) {
    $dir = opendir($src);
    @mkdir($dst, 0755, true);
    while (false !== ($file = readdir($dir))) {
        if ($file !== '.' && $file !== '..') {
            if (is_dir($src . '/' . $file)) {
                copyTree($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

// Copy source trees into staging
copyTree($repoDir . '/engine', $tempBuild . '/engine');
copyTree($repoDir . '/cron',   $tempBuild . '/cron');
copyTree($repoDir . '/docs',   $tempBuild . '/docs');
copy($xmlFile, $tempBuild . '/plugin.xml');
copy($repoDir . '/README.md', $tempBuild . '/README.md');

// Remove any non-dist docs in staging (html landing, zip copies)
@unlink($tempBuild . '/docs/index.html');
@unlink($tempBuild . '/docs/update.json');
@unlink($tempBuild . '/docs/dle-social-autopost.xml');
$docsZips = glob($tempBuild . '/docs/*.zip');
if ($docsZips) {
    foreach ($docsZips as $dz) @unlink($dz);
}

$cmd = 'cd ' . escapeshellarg($tempBuild) . ' && zip -q -r -9 ' . escapeshellarg($versionedZip) . ' .';
exec($cmd, $zipOut, $zipCode);

// Cleanup staging
exec('rm -rf ' . escapeshellarg($tempBuild));

if ($zipCode !== 0 || !file_exists($versionedZip)) {
    fwrite(STDERR, "Error building ZIP archive!\n");
    exit(1);
}

// Create generic dle-social-autopost.zip copy
copy($versionedZip, $latestZip);
copy($versionedZip, $docsVerZip);
copy($versionedZip, $docsLatestZip);

$zipSize = filesize($versionedZip);
echo "  [OK] Created archive: dist/dle-social-autopost-v{$targetVersion}.zip (" . round($zipSize / 1024, 1) . " KB)\n";
echo "  [OK] Created archive: dist/dle-social-autopost.zip (latest)\n";

// 9. Verify ZIP archive contents
echo "\nVerifying ZIP archive contents...\n";
$zip = new ZipArchive();
if ($zip->open($versionedZip) !== true) {
    fwrite(STDERR, "Error: Cannot open created zip archive!\n");
    exit(1);
}

$requiredEntries = [
    'plugin.xml',
    'engine/classes/autopost/Autopost.php',
    'engine/classes/autopost/Config.php',
    'engine/classes/autopost/Logger.php',
    'engine/classes/autopost/MediaHelper.php',
    'engine/classes/autopost/Queue.php',
    'engine/classes/autopost/TelegramClient.php',
    'engine/classes/autopost/TemplateEngine.php',
    'engine/classes/autopost/VkClient.php',
    'engine/inc/autopost.php',
    'engine/skins/images/autopost.png',
    'cron/autopost.php',
];

foreach ($requiredEntries as $entry) {
    if ($zip->locateName($entry) === false) {
        fwrite(STDERR, "Error: Missing {$entry} in zip package!\n");
        $zip->close();
        exit(1);
    }
}
$zip->close();
echo "  [OK] All " . count($requiredEntries) . " mandatory files verified in archive.\n";

echo "\n============================================\n";
echo "BUILD SUCCESSFUL! Version {$targetVersion}\n";
echo "Archive (versioned): dist/dle-social-autopost-v{$targetVersion}.zip\n";
echo "Archive (latest):    dist/dle-social-autopost.zip\n";
echo "Plugin XML:          dist/dle-social-autopost.xml\n";
echo "============================================\n";
