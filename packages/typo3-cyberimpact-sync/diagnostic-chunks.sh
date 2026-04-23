#!/bin/bash

# Script de diagnostic des chunks Cyberimpact
# Usage: bash diagnostic-chunks.sh

cd "$(dirname "$0")" || exit

php -r "
\$pdo = new PDO('mysql:host=127.0.0.1;dbname=typo3_v14;charset=utf8mb4', 'root', 'Revenu01');

echo \"\\n\" . str_repeat('=', 60) . \"\\n\";
echo \"DIAGNOSTIC DES CHUNKS CYBERIMPACT\";
echo \"\\n\" . str_repeat('=', 60) . \"\\n\\n\";

// Stats globales
echo \"📊 STATISTIQUES GLOBALES:\\n\\n\";
\$stats = \$pdo->query('SELECT status, COUNT(*) as cnt FROM tx_cyberimpactsync_chunk GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
foreach (\$stats as \$s) {
    \$status = \$s['status'];
    \$cnt = \$s['cnt'];
    \$icon = match(\$status) {
        'done' => '✅',
        'pending' => '⏳',
        'processing' => '⚠️',
        'failed' => '❌',
        default => '❓'
    };
    echo \"  • \$icon  \$status: \$cnt\\n\";
}

// Chunks en processing
echo \"\\n\\n📌 CHUNKS EN PROCESSING (BLOQUÉS):\\n\\n\";
\$processing = \$pdo->query(
    'SELECT uid, run_uid, chunk_index, attempt_count, UNIX_TIMESTAMP(NOW()) - tstamp AS age_seconds 
     FROM tx_cyberimpactsync_chunk WHERE status = \"processing\" ORDER BY age_seconds DESC'
)->fetchAll(PDO::FETCH_ASSOC);

if (empty(\$processing)) {
    echo \"  ✅ AUCUN CHUNK BLOQUÉ - Tout est OK!\\n\";
} else {
    echo \"  ⚠️  CHUNKS BLOQUÉS DÉTECTÉS:\\n\\n\";
    foreach (\$processing as \$c) {
        \$ageMin = ceil(\$c['age_seconds'] / 60);
        echo sprintf(
            \"    • Chunk %d (UID %d, Run %d): bloqué depuis %d min, %d tentatives\\n\",
            \$c['chunk_index'],
            \$c['uid'],
            \$c['run_uid'],
            \$ageMin,
            \$c['attempt_count']
        );
    }
}

// Chunks à risque (3+ tentatives)
echo \"\\n\\n⚡ CHUNKS À RISQUE (3+ tentatives):\\n\\n\";
\$risky = \$pdo->query(
    'SELECT uid, run_uid, chunk_index, status, attempt_count FROM tx_cyberimpactsync_chunk 
     WHERE attempt_count >= 3 ORDER BY attempt_count DESC'
)->fetchAll(PDO::FETCH_ASSOC);

if (empty(\$risky)) {
    echo \"  ✅ Aucun chunk à risque\\n\";
} else {
    echo sprintf(\"  ⚠️  %d chunk(s) à risque:\\n\\n\", count(\$risky));
    foreach (\$risky as \$r) {
        echo sprintf(
            \"    • Chunk %d (%s): %d tentatives\\n\",
            \$r['chunk_index'],
            \$r['status'],
            \$r['attempt_count']
        );
    }
}

// Runs actifs
echo \"\\n\\n🔄 DERNIERS RUNS:\\n\\n\";
\$runs = \$pdo->query(
    'SELECT DISTINCT run_uid FROM tx_cyberimpactsync_chunk ORDER BY run_uid DESC LIMIT 5'
)->fetchAll(PDO::FETCH_ASSOC);

foreach (\$runs as \$run) {
    \$runUid = \$run['run_uid'];
    \$counts = \$pdo->prepare(
        'SELECT status, COUNT(*) as cnt FROM tx_cyberimpactsync_chunk WHERE run_uid = ? GROUP BY status'
    );
    \$counts->execute([\$runUid]);
    \$summary = [];
    foreach (\$counts->fetchAll(PDO::FETCH_ASSOC) as \$c) {
        \$summary[\$c['status']] = \$c['cnt'];
    }
    
    echo sprintf(
        \"  • Run #%d: pending=%d ⏳,  processing=%d ⚠️,  done=%d ✅,  failed=%d ❌\\n\",
        \$runUid,
        \$summary['pending'] ?? 0,
        \$summary['processing'] ?? 0,
        \$summary['done'] ?? 0,
        \$summary['failed'] ?? 0
    );
}

echo \"\\n\" . str_repeat('=', 60) . \"\\n\\n\";
"
