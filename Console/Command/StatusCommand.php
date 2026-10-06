<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Console\Command;

use Kingletas\ReadSplit\Model\Replica\Breaker;
use Kingletas\ReadSplit\Model\Settings;
use Kingletas\ReadSplit\Model\SettingsReader;
use Kingletas\ReadSplit\Model\SettingsState;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Says whether the read split is in use, and fails when env.php configures it and the store is doing nothing with it.
 */
class StatusCommand extends Command
{
    public function __construct(
        private readonly SettingsReader $settingsReader,
        private readonly Breaker $breaker,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription(
            (string) __('Show whether reads go to the replica, and fail when it is configured but not in use.')
        );

        parent::configure();
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->settingsReader->forTarget();

        $output->writeln((string) __('Read split: %1', $this->describe($settings->state())));

        if ($settings->state() === SettingsState::Refused) {
            $output->writeln((string) __('Reason: %1', $settings->reason()));

            return Command::FAILURE;
        }

        if ($settings->isActive()) {
            $this->writeReplica($settings, $output);
        }

        return Command::SUCCESS;
    }

    private function writeReplica(Settings $settings, OutputInterface $output): void
    {
        [$host, $port, $dbname] = $settings->replicaIdentity();
        $output->writeln((string) __('Replica: %1, port %2, database %3', $host, $port, $dbname));
        $output->writeln((string) __(
            'Settings: max_lag %1 s, position lifetime %2 s, pooled %3, read timeout %4 s, connect timeout %5 s',
            $settings->maxLag(),
            $settings->positionLifetime(),
            $settings->isPooled() ? 'yes' : 'no',
            $settings->readTimeout(),
            $settings->replicaConfig()['driver_options'][PDO::ATTR_TIMEOUT] ?? '?'
        ));

        $breaker = $this->breaker->withReplicaHost($host . ':' . $port);

        $hidden = $breaker->hiddenFromHere();

        if ($hidden !== '') {
            $output->writeln((string) __(
                'Breaker: unknown from here. The web server may keep its markers in %1, where this user does not '
                . 'look. Run this as the web server\'s user.',
                $hidden
            ));

            return;
        }

        $open = $breaker->openState();
        $output->writeln(
            $open === null
                ? (string) __('Breaker: closed')
                : (string) __(
                    'Breaker: open, retried in %1 seconds, kept in %2',
                    $open['remaining'],
                    $open['keptIn'] === 'var' ? 'var/' : (string) __('the system temp directory')
                )
        );
    }

    private function describe(SettingsState $state): string
    {
        $phrase = match ($state) {
            SettingsState::Active => __('active'),
            SettingsState::NotConfigured => __('not configured'),
            SettingsState::SwitchedOff => __('switched off in env.php'),
            SettingsState::Refused, SettingsState::OtherConnection => __('configured but not in use'),
        };

        return (string) $phrase;
    }
}
