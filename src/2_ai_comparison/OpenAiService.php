<?php
use App\Services\ProxyManager;
use App\Services\Support\Cache;
use App\Services\Support\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAIService
{
    private const BASE_URI = 'https://api.openai.com/v1/';
    private const EMBEDDING_MODEL = 'text-embedding-3-large';
    private const BALANCE_ENDPOINT = 'dashboard/billing/credit_balance';

    private $client;
    private $api_key;

    public function __construct(string $api_key)
    {
        $proxy = ProxyManager::make("worldwide");

        $this->api_key = $api_key;
        $this->client = new Client([
            'base_uri' => self::BASE_URI,
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'proxy' => $proxy->getGuzzleProxySettingString()
        ]);
    }

    /**
     * Получение текстового embedding через OpenAI API
     *
     * @param string $text Текст для обработки
     * @return string|null JSON-строка с embedding или null при ошибке
     */
    public function getEmbedding(string $text): ?string
    {
        if (empty($text)) {
            return null;
        }

        $cache_key = 'openai_embedding_' . self::EMBEDDING_MODEL . '_' . md5($text);

        return Cache::rememberForever($cache_key, function () use ($text) {
            try {
                $response = $this->client->post('embeddings', [
                    'json' => [
                        'input' => $text,
                        'model' => self::EMBEDDING_MODEL,
                    ]
                ]);

                $data = json_decode($response->getBody(), true);

                if ($response->getStatusCode() !== 200 || empty($data['data'][0]['embedding'])) {
                    throw new RuntimeException('Invalid API response');
                }

                return json_encode($data['data'][0]['embedding']);

            } catch (GuzzleException $e) {
                Log::error('OpenAI API request failed', [
                    'error' => $e->getMessage(),
                    'text_sample' => Str::limit($text, 50),
                    'code' => $e->getCode(),
                ]);
                return null;
            } catch (\Exception $e) {
                Log::error('OpenAI embedding processing failed', [
                    'error' => $e->getMessage(),
                    'text_sample' => Str::limit($text, 50),
                ]);
                return null;
            }
        });
    }
}