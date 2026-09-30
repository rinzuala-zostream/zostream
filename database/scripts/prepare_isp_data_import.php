<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php prepare_isp_data_import.php <source.sql> <output.sql>\n");
    exit(1);
}

[$script, $sourcePath, $outputPath] = $argv;

$sourceRealPath = realpath($sourcePath);
if ($sourceRealPath === false || ! is_file($sourceRealPath) || ! is_readable($sourceRealPath)) {
    fwrite(STDERR, "The source SQL file is not readable.\n");
    exit(1);
}

$outputDirectory = dirname($outputPath);
if (! is_dir($outputDirectory) || ! is_writable($outputDirectory)) {
    fwrite(STDERR, "The output directory is not writable.\n");
    exit(1);
}

if (realpath($outputPath) === $sourceRealPath) {
    fwrite(STDERR, "The source file cannot be overwritten.\n");
    exit(1);
}

$tableMap = [
    'users' => 'isp_users',
    'routers' => 'routers',
    'packages' => 'packages',
    'customers' => 'customers',
    'branches' => 'branches',
    'branch_package' => 'branch_package',
    'payments' => 'payments',
    'payment_checkouts' => 'payment_checkouts',
    'nas' => 'nas',
    'radcheck' => 'radcheck',
    'radreply' => 'radreply',
    'radacct' => 'radacct',
    'radpostauth' => 'radpostauth',
    'radusergroup' => 'radusergroup',
    'radgroupcheck' => 'radgroupcheck',
    'radgroupreply' => 'radgroupreply',
];

// Children are cleared before parents. FOREIGN_KEY_CHECKS is also disabled,
// but this order makes the intended scope explicit for manual review.
$truncateOrder = [
    'payment_checkouts',
    'payments',
    'branch_package',
    'customers',
    'isp_users',
    'branches',
    'packages',
    'radacct',
    'radpostauth',
    'radcheck',
    'radreply',
    'radusergroup',
    'radgroupcheck',
    'radgroupreply',
    'nas',
    'routers',
];

$source = fopen($sourceRealPath, 'rb');
$output = fopen($outputPath, 'wb');

if ($source === false || $output === false) {
    fwrite(STDERR, "Unable to open the SQL files.\n");
    exit(1);
}

$header = <<<'SQL'
-- ZoStream ISP data-only restore
-- Generated from the legacy zostream_isp backup.
-- This file intentionally contains no DROP TABLE or CREATE TABLE statements.
-- Run the ZoStream migrations before importing it.
-- Only ISP-owned tables are cleared and restored.

SET NAMES utf8mb4;
SET @OLD_TIME_ZONE = @@TIME_ZONE;
SET TIME_ZONE = '+00:00';
SET @OLD_UNIQUE_CHECKS = @@UNIQUE_CHECKS;
SET UNIQUE_CHECKS = 0;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

SQL;

fwrite($output, $header);

foreach ($truncateOrder as $table) {
    fwrite($output, sprintf("TRUNCATE TABLE `%s`;\n", $table));
}

fwrite($output, "\n-- Legacy ISP rows\n");

$statementCounts = array_fill_keys(array_values($tableMap), 0);

while (($line = fgets($source)) !== false) {
    if (! preg_match('/^INSERT INTO `([^`]+)` VALUES /', $line, $matches)) {
        continue;
    }

    $sourceTable = $matches[1];
    if (! isset($tableMap[$sourceTable])) {
        continue;
    }

    $targetTable = $tableMap[$sourceTable];
    if ($targetTable !== $sourceTable) {
        $line = preg_replace(
            '/^INSERT INTO `'.preg_quote($sourceTable, '/').'`/',
            'INSERT INTO `'.$targetTable.'`',
            $line,
            1
        );
    }

    fwrite($output, $line);
    $statementCounts[$targetTable]++;
}

fwrite($output, <<<'SQL'

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS = @OLD_UNIQUE_CHECKS;
SET TIME_ZONE = @OLD_TIME_ZONE;

SQL);

fclose($source);
fclose($output);

foreach ($statementCounts as $table => $count) {
    fwrite(STDOUT, sprintf("%s: %d INSERT statement(s)\n", $table, $count));
}

fwrite(STDOUT, sprintf("Created %s (%d bytes)\n", $outputPath, filesize($outputPath)));
