<?php

declare(strict_types=1);

namespace Shared\Ai;

use Shared\Domain\Setting\SettingRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

use function array_filter;
use function array_map;
use function array_values;

/**
 * Thin helper for the Symfony AI platform (LiteLLM gateway).
 *
 * Modules can inject this service to call the configured AI model with a
 * runtime-selectable model (stored in the "setting" table, key ai_model)
 * instead of the compile-time default agent. Usage is measured by LiteLLM
 * and surfaced through getUsage().
 */
final readonly class AiAssistant
{
    public function __construct(
        #[Autowire(service: 'ai.platform.generic.litellm')]
        private PlatformInterface $platform,
        private SettingRepository $settings,
        #[Autowire(env: 'LITELLM_HOST_URL')]
        private string $litellmHostUrl,
        #[Autowire(env: 'LITELLM_API_KEY')]
        private string $litellmApiKey,
        #[Autowire(env: 'LITELLM_ADMIN_KEY')]
        private string $litellmAdminKey,
    ) {
    }

    public function chat(string $userMessage, ?string $model = null): string
    {
        $model ??= $this->getActiveModel();

        $messages = new MessageBag(
            Message::forSystem('Je bent een behulpzame assistent voor het OpenVWS Woo-publicatieplatform. Antwoord kort en in het Nederlands.'),
            Message::ofUser($userMessage),
        );

        return $this->platform->invoke($model, $messages)->asText();
    }

    public function getActiveModel(): string
    {
        return $this->settings->get('ai_model', 'qwen-agent') ?? 'qwen-agent';
    }

    public function setActiveModel(string $model): void
    {
        $this->settings->set('ai_model', $model);
    }

    /**
     * @return list<string>
     */
    public function getAvailableModels(): array
    {
        try {
            $response = $this->client()->request('GET', $this->litellmHostUrl . '/v1/models', [
                'headers' => ['Authorization' => 'Bearer ' . $this->litellmApiKey],
                'timeout' => 5,
            ]);

            $data = $response->toArray(false);

            return array_values(array_filter(
                array_map(static fn (array $m): string => (string) ($m['id'] ?? ''), $data['data'] ?? []),
                static fn (string $id): bool => $id !== '',
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Aggregated spend data from LiteLLM for the last $days days.
     *
     * @return array{
     *   totalTokens: int,
     *   totalSpend: float,
     *   requests: int,
     *   budget: ?float,
     *   byModel: list<array{model:string,requests:int,promptTokens:int,completionTokens:int,totalTokens:int,spend:float}>,
     *   byDay: list<array{date:string,totalTokens:int,spend:float}>,
     *   logs: list<array<string,mixed>>
     * }
     */
    public function getUsage(int $days = 7): array
    {
        $usage = [
            'totalTokens' => 0,
            'totalSpend' => 0.0,
            'requests' => 0,
            'budget' => null,
            'byModel' => [],
            'byDay' => [],
            'logs' => [],
        ];

        try {
            $response = $this->client()->request('GET', $this->litellmHostUrl . '/spend/keys', [
                'headers' => ['Authorization' => 'Bearer ' . $this->litellmAdminKey],
                'timeout' => 5,
            ]);
            foreach ($response->toArray(false) as $key) {
                if (($key['key_alias'] ?? null) === 'openvws-demo') {
                    $usage['budget'] = isset($key['max_budget']) ? (float) $key['max_budget'] : null;
                }
            }
        } catch (\Throwable) {
            // budget lookup is best effort
        }

        $period = new \DatePeriod(
            (new \DateTimeImmutable('now'))->modify('- ' . ($days - 1) . ' days')->setTime(0, 0),
            new \DateInterval('P1D'),
            (new \DateTimeImmutable('now'))->modify('+1 day')->setTime(0, 0),
        );
        foreach ($period as $day) {
            $usage['byDay'][$day->format('Y-m-d')] = ['date' => $day->format('Y-m-d'), 'totalTokens' => 0, 'spend' => 0.0];
        }

        try {
            $response = $this->client()->request('GET', $this->litellmHostUrl . '/spend/logs', [
                'headers' => ['Authorization' => 'Bearer ' . $this->litellmAdminKey],
                'query' => [
                    'start_date' => (new \DateTimeImmutable('now'))->modify('- ' . ($days - 1) . ' days')->format('Y-m-d'),
                    'end_date' => (new \DateTimeImmutable('now'))->format('Y-m-d'),
                    'page_size' => 200,
                ],
                'timeout' => 5,
            ]);

            $data = $response->toArray(false);
            $rows = $data['data'] ?? $data;

            /** @var array<string,array{model:string,requests:int,promptTokens:int,completionTokens:int,totalTokens:int,spend:float}> $byModel */
            $byModel = [];

            foreach ($rows as $row) {
                if (! \is_array($row)) {
                    continue;
                }

                $model = (string) ($row['model'] ?? '');
                $promptTokens = (int) ($row['prompt_tokens'] ?? 0);
                $completionTokens = (int) ($row['completion_tokens'] ?? 0);
                $tokens = (int) ($row['total_tokens'] ?? ($promptTokens + $completionTokens));
                $spend = (float) ($row['spend'] ?? 0.0);
                $status = (string) ($row['status'] ?? '');

                $usage['totalTokens'] += $tokens;
                $usage['totalSpend'] += $spend;
                $usage['requests'] += 1;
                $usage['logs'][] = [
                    'model' => $model,
                    'tokens' => $tokens,
                    'spend' => $spend,
                    'status' => $status,
                    'at' => (string) ($row['startTime'] ?? ''),
                ];

                $byModel[$model] ??= [
                    'model' => $model,
                    'requests' => 0,
                    'promptTokens' => 0,
                    'completionTokens' => 0,
                    'totalTokens' => 0,
                    'spend' => 0.0,
                ];
                $byModel[$model]['requests'] += 1;
                $byModel[$model]['promptTokens'] += $promptTokens;
                $byModel[$model]['completionTokens'] += $completionTokens;
                $byModel[$model]['totalTokens'] += $tokens;
                $byModel[$model]['spend'] += $spend;

                $day = substr((string) ($row['startTime'] ?? ''), 0, 10);
                if ($day !== '' && isset($usage['byDay'][$day])) {
                    $usage['byDay'][$day]['totalTokens'] += $tokens;
                    $usage['byDay'][$day]['spend'] += $spend;
                }
            }

            // Sort by spend descending, then tokens
            uasort($byModel, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend'] ?: $b['totalTokens'] <=> $a['totalTokens']);
            $usage['byModel'] = array_values($byModel);
        } catch (\Throwable) {
            // spend logs are best effort
        }

        $usage['byDay'] = array_values($usage['byDay']);

        return $usage;
    }

    private function client(): HttpClientInterface
    {
        return HttpClient::create();
    }
}