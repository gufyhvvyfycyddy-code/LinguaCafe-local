<?php

namespace App\Console\Commands;

use App\Services\LegacyWordCardMigrationClassifier;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ClassifyLegacyWordCards extends Command
{
    protected $signature = 'reviews:classify-legacy-word-cards {--user_id=} {--language=}';

    protected $description = 'Emit a read-only classification report for legacy word review cards.';

    public function handle(LegacyWordCardMigrationClassifier $classifier): int
    {
        $rawUserId = $this->option('user_id');
        $rawLanguage = $this->option('language');

        $userId = null;
        if ($rawUserId !== null) {
            $validated = filter_var($rawUserId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($validated === false || ! ctype_digit((string) $rawUserId)) {
                return $this->invalid('The --user_id option must be a positive base-10 integer.');
            }
            $userId = (int) $validated;
        }

        $language = null;
        if ($rawLanguage !== null) {
            if (! is_string($rawLanguage) || preg_match('/^[a-z][a-z0-9_-]*$/D', $rawLanguage) !== 1) {
                return $this->invalid('The --language option must be a lower-case language identifier.');
            }
            $language = $rawLanguage;
        }

        $json = json_encode(
            $classifier->classify($userId, $language),
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        );
        $this->output->write($json."\n", false, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function invalid(string $message): int
    {
        if ($this->output instanceof ConsoleOutputInterface) {
            $this->output->getErrorOutput()->writeln($message);
        } else {
            $this->output->writeln($message);
        }

        return self::FAILURE;
    }
}
