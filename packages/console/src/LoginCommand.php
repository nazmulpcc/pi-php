<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\AI\OAuth\OAuthAuthInfo;
use Pi\AI\OAuth\OAuthLoginCallbacks;
use Pi\AI\OAuth\OAuthPrompt;
use Pi\AI\OAuth\OAuthProviderInterface;
use Pi\CodingAgent\Support\PromiseBlocker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class LoginCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('login')
            ->setDescription('Authenticate a provider and persist credentials to auth.json.')
            ->addArgument('provider', InputArgument::REQUIRED, 'OAuth provider id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $provider = (string) $input->getArgument('provider');
        $context = (new ConsoleContextFactory)->create();
        $oauthProvider = $this->findProvider($context, $provider);
        $asyncCodeReader = new AsyncManualCodeReader;

        $callbacks = new OAuthLoginCallbacks(
            onAuth: function (OAuthAuthInfo $info) use ($io): void {
                $io->writeln($info->instructions ?? 'Open this URL to continue authentication:');
                $io->writeln($info->url);
            },
            onPrompt: function (OAuthPrompt $prompt) use ($io): string {
                return (string) $io->ask($prompt->message, null);
            },
            onProgress: function (string $message) use ($io): void {
                $io->writeln($message);
            },
            onManualCodeInput: function () use ($oauthProvider, $asyncCodeReader, $output, $io): mixed {
                if (! $oauthProvider->usesCallbackServer()) {
                    return (string) $io->ask('Paste the authorization code');
                }

                return $asyncCodeReader->read($output);
            },
        );

        PromiseBlocker::block($context->authStorage->login($provider, $callbacks));
        $io->success(sprintf('Stored credentials for %s.', $provider));

        return 0;
    }

    private function findProvider(ConsoleContext $context, string $providerId): OAuthProviderInterface
    {
        foreach ($context->authStorage->getOAuthProviders() as $provider) {
            if ($provider->getId() === $providerId) {
                return $provider;
            }
        }

        throw new \RuntimeException(sprintf('Unknown OAuth provider: %s', $providerId));
    }
}
