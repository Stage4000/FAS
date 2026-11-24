<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/growth.php';

try {
    $growth = fas_growth();
    $command = $argv[1] ?? 'check';
    $lastId = (int) $growth->run("SELECT seq FROM sqlite_sequence WHERE name = 'growth_messages'")->fetchColumn();

    if ($command === 'check' && count($argv) <= 2) {
        echo json_encode([
            'confirmation_ready' => $growth->confirmationReady(),
            'marketing_ready' => $growth->ready(),
            'current_queue_id' => $lastId,
            'queued_confirmations' => (int) $growth->run(
                "SELECT COUNT(*) FROM growth_messages WHERE kind = 'confirmation' AND status = 'queued'"
            )->fetchColumn(),
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit;
    }

    $cutoffOption = '--after-id=';
    if ($command !== 'send-new' || count($argv) !== 4 || $argv[2] !== '--deliver' || !str_starts_with($argv[3], $cutoffOption)) {
        throw new InvalidArgumentException('Usage: check or send-new --deliver --after-id=<queue ID at cutover>');
    }
    $cutoff = filter_var(substr($argv[3], strlen($cutoffOption)), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => $lastId],
    ]);
    if ($cutoff === false) {
        throw new InvalidArgumentException('The queue ID is invalid for this database.');
    }

    $result = $growth->sendConfirmationBatch(static function (array $mail): bool {
        $headers = [
            'From: Flip and Strip <' . $mail['from'] . '>',
            'Reply-To: ' . $mail['reply_to'],
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        return mail($mail['to'], $mail['subject'], $mail['body'], implode("\r\n", $headers));
    }, 20, null, $cutoff);

    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
