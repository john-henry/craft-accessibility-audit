<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\helpers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * Where the plugin talks to Anthropic, and what it says when it does.
 *
 * Five places send a request: alt text on demand and on the queue, a drafted
 * VPAT remark, a plainer-wording suggestion, and the key check on the settings
 * screen. They ask for different things, so each keeps its own request, but the
 * address, the API version and the model are the same for all of them and are
 * settled here. A model named in five files is a model that gets changed in
 * four.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.5.0
 */
class Anthropic
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The messages endpoint.
     */
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /**
     * @var string The API version this plugin's requests are written against.
     */
    public const API_VERSION = '2023-06-01';

    /**
     * @var string The model every request uses. Haiku: the work is short,
     *      high-volume and reads an image or a paragraph rather than reasoning
     *      about a codebase.
     */
    public const MODEL = 'claude-haiku-4-5-20251001';

    /**
     * @var int How many tokens an alt-text reply may run to. Alt text that
     *      needs more than this is too long to be read out as alt text.
     */
    public const ALT_TEXT_MAX_TOKENS = 150;

    /**
     * @var int Seconds a request may run for. Guzzle waits forever by default,
     *      and these calls are made from a control panel request and from a
     *      queue worker: an unbounded one holds the button, or the worker,
     *      until the process is killed.
     */
    public const TIMEOUT = 30;

    /**
     * @var int Seconds to spend reaching the API before giving up. A host that
     *      is not answering at all is told apart from a reply that is slow to
     *      come back.
     */
    public const CONNECT_TIMEOUT = 5;

    /**
     * @var int Seconds the settings screen's key check may run for. Shorter
     *      than a working request, since it asks for five tokens and somebody
     *      is watching the button.
     */
    public const KEY_CHECK_TIMEOUT = 15;

    // Public Methods
    // =========================================================================

    /**
     * The headers every request carries.
     *
     * @param string $apiKey The resolved API key.
     * @return array<string, string> The request headers.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function headers(string $apiKey): array
    {
        return [
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ];
    }

    /**
     * The Guzzle client options every request is made with.
     *
     * @param int $timeout Seconds the request may run for.
     * @return array<string, int> The client options.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function clientConfig(int $timeout = self::TIMEOUT): array
    {
        return [
            'timeout' => $timeout,
            'connect_timeout' => self::CONNECT_TIMEOUT,
        ];
    }

    /**
     * Asks for one image to be described, and hands back what came out.
     *
     * @param Client $client The HTTP client.
     * @param string $apiKey The resolved API key.
     * @param array<string, mixed> $imageSource The image content block source.
     * @param string $prompt The prompt to send with it.
     * @return string The alt text, empty where the reply carried none.
     * @throws GuzzleException
     * @throws JsonException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.5.0
     */
    public static function describeImage(Client $client, string $apiKey, array $imageSource, string $prompt): string
    {
        $response = $client->post(self::ENDPOINT, [
            'headers' => self::headers($apiKey),
            'json' => [
                'model' => self::MODEL,
                'max_tokens' => self::ALT_TEXT_MAX_TOKENS,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => $imageSource],
                        ['type' => 'text',  'text' => $prompt],
                    ],
                ]],
            ],
        ]);

        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return trim($body['content'][0]['text'] ?? '');
    }
}
