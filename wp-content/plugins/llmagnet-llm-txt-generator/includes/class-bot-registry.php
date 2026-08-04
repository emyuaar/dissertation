<?php
/**
 * Bot Registry class
 *
 * Single canonical source of truth for known LLM bot / AI crawler definitions.
 * Consumed by Analytics (user-agent detection, UTM click attribution) and
 * Visibility_Score (bot type classification) so the lists can never drift.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

/**
 * Canonical registry of LLM bots and crawlers
 */
class Bot_Registry {
    /**
     * Canonical bot map.
     *
     * Each entry is keyed by the canonical bot name and contains:
     * - type:        Bot category: user_search_ai | crawler_ai | unknown.
     * - ua_pattern:  Regex matched against the HTTP user agent (null = no UA detection).
     *                Order of entries with a ua_pattern is significant — it preserves
     *                the historical detection precedence from Analytics.
     * - utm_pattern: Regex body matched against utm_source for click attribution
     *                (null = no UTM detection).
     * - click_row:   Whether a summary row is pre-seeded in the bot clicks table.
     *
     * @var array
     */
    private static $bots = [
        'ChatGPT' => [
            'type'        => 'user_search_ai',
            // OAI-SearchBot is listed explicitly so detection does not rely on
            // the "+https://openai.com/..." URL fragment OpenAI appends to the
            // UA (the "OpenAI" alternative still covers that fallback path).
            'ua_pattern'  => '/ChatGPT|ChatGPT-User|GPTBot|OAI-SearchBot|OpenAI|GPT-4/i',
            'utm_pattern' => '.*chatgpt\.com.*',
            'click_row'   => true,
        ],
        'Claude' => [
            'type'        => 'user_search_ai',
            'ua_pattern'  => '/Claude|Anthropic|ClaudeBot/i',
            'utm_pattern' => '.*claude\.ai.*',
            'click_row'   => true,
        ],
        'Gemini' => [
            'type'        => 'user_search_ai',
            'ua_pattern'  => '/Gemini|GeminiBot|Gemini-User|Bard|Google-Extended|GoogleBot-Extended/i',
            'utm_pattern' => '.*gemini\.google\.com.*|.*bard\.google\.com.*',
            'click_row'   => true,
        ],
        'Bing' => [
            'type'        => 'crawler_ai',
            'ua_pattern'  => '/BingBot|BingChat|Sydney/i',
            'utm_pattern' => null,
            'click_row'   => false,
        ],
        'Perplexity' => [
            'type'        => 'user_search_ai',
            'ua_pattern'  => '/Perplexity|PerplexityBot/i',
            'utm_pattern' => '.*perplexity.*',
            'click_row'   => true,
        ],
        'Llama' => [
            'type'        => 'crawler_ai',
            // meta-externalagent / meta-externalfetcher are Meta's actual AI
            // crawler UA tokens (the strings already blocked via robots.txt).
            // Without them the Llama-only pattern never matched real Meta
            // traffic, so those visits were blocked but never attributed.
            'ua_pattern'  => '/Llama|Meta-Llama|LlamaBot|meta-externalagent|meta-externalfetcher/i',
            'utm_pattern' => null,
            'click_row'   => false,
        ],
        'Mistral' => [
            'type'        => 'crawler_ai',
            'ua_pattern'  => '/Mistral|MistralAI/i',
            'utm_pattern' => null,
            'click_row'   => false,
        ],
        'Grok' => [
            'type'        => 'user_search_ai',
            'ua_pattern'  => '/Grok|GrokBot|xAI|X-AI/i',
            'utm_pattern' => null,
            'click_row'   => false,
        ],
        'DeepSeek' => [
            'type'        => 'user_search_ai',
            'ua_pattern'  => '/DeepSeek|DeepSeekBot|DeepSeekAI/i',
            'utm_pattern' => '.*deepseek\.com.*',
            'click_row'   => true,
        ],
        'Other LLM' => [
            'type'        => 'unknown',
            'ua_pattern'  => '/LLM|AI Bot|AI-Bot|AIBot|AI Crawler|AICrawler/i',
            'utm_pattern' => null,
            'click_row'   => false,
        ],
        'OpenAI' => [
            'type'        => 'crawler_ai',
            'ua_pattern'  => null,
            'utm_pattern' => '.*openai\.com.*',
            'click_row'   => true,
        ],
        'Copilot' => [
            'type'        => 'user_search_ai',
            'ua_pattern'  => null,
            'utm_pattern' => '.*copilot\.microsoft\.com.*|.*edgepilot.*|.*edgeservices.*',
            'click_row'   => true,
        ],
        'Writesonic' => [
            'type'        => 'unknown',
            'ua_pattern'  => null,
            'utm_pattern' => '.*writesonic\.com.*',
            'click_row'   => true,
        ],
        'Copy.ai' => [
            'type'        => 'unknown',
            'ua_pattern'  => null,
            'utm_pattern' => '.*copy\.ai.*',
            'click_row'   => true,
        ],
        'Nimble' => [
            'type'        => 'unknown',
            'ua_pattern'  => null,
            'utm_pattern' => '.*nimble\.ai.*',
            'click_row'   => true,
        ],
        'iAsk' => [
            'type'        => 'unknown',
            'ua_pattern'  => null,
            'utm_pattern' => '.*iask\.ai.*',
            'click_row'   => true,
        ],
        'Aitastic' => [
            'type'        => 'unknown',
            'ua_pattern'  => null,
            'utm_pattern' => '.*aitastic\.app.*',
            'click_row'   => true,
        ],
        'BNNgpt' => [
            'type'        => 'unknown',
            'ua_pattern'  => null,
            'utm_pattern' => '.*bnngpt\.com.*',
            'click_row'   => true,
        ],
        'Chat-GPT.org' => [
            'type'        => 'unknown',
            'ua_pattern'  => null,
            'utm_pattern' => '.*chat-gpt\.org.*',
            'click_row'   => true,
        ],
        'Hugging Face' => [
            'type'        => 'unknown',
            'ua_pattern'  => null,
            'utm_pattern' => '.*huggingface\.co.*',
            'click_row'   => true,
        ],

        // AI training-data crawlers absorbed from Robots_Txt's supplemental
        // list at the Phase D gate. Keys intentionally match the robots.txt
        // directive keys used by the llmagnet_robots_ai_bots policy option,
        // and the entries sit at the tail so detection precedence and
        // robots.txt group order are unchanged.
        'Applebot-Extended' => [
            // Robots-only token: Apple crawls as plain "Applebot" and uses
            // Applebot-Extended solely as a robots.txt opt-out signal, so a
            // UA pattern here would misattribute Apple's search crawler.
            'type'        => 'crawler_ai',
            'ua_pattern'  => null,
            'utm_pattern' => null,
            'click_row'   => false,
        ],
        'Bytespider' => [
            'type'        => 'crawler_ai',
            'ua_pattern'  => '/Bytespider/i',
            'utm_pattern' => null,
            'click_row'   => false,
        ],
        'CCBot' => [
            'type'        => 'crawler_ai',
            'ua_pattern'  => '/CCBot/i',
            'utm_pattern' => null,
            'click_row'   => false,
        ],
        'Amazonbot' => [
            'type'        => 'crawler_ai',
            'ua_pattern'  => '/Amazonbot/i',
            'utm_pattern' => null,
            'click_row'   => false,
        ],
        'Cohere' => [
            'type'        => 'crawler_ai',
            'ua_pattern'  => '/cohere-ai|cohere-training-data-crawler/i',
            'utm_pattern' => null,
            'click_row'   => false,
        ],
    ];

    /**
     * Compiled lowercase needle list for the fast-fail user-agent pre-check.
     *
     * Every literal alternative in every ua_pattern above must contain at least
     * one of these needles as a case-insensitive substring, so a UA that fails
     * this pre-scan can never match a ua_pattern. Keep in sync with $bots.
     *
     * @var array
     */
    private static $ua_needles = [
        'chatgpt',
        'gptbot',
        'oai-searchbot',
        'openai',
        'gpt-4',
        'claude',
        'anthropic',
        'gemini',
        'bard',
        'google-extended',
        'googlebot-extended',
        'bingbot',
        'bingchat',
        'sydney',
        'perplexity',
        'llama',
        'meta-externalagent',
        'meta-externalfetcher',
        'mistral',
        'grok',
        'xai',
        'x-ai',
        'deepseek',
        'llm',
        'ai bot',
        'ai-bot',
        'aibot',
        'ai crawler',
        'aicrawler',
        'bytespider',
        'ccbot',
        'amazonbot',
        'cohere',
    ];

    /**
     * Get the full canonical bot map
     *
     * @return array
     */
    public static function get_bots() {
        return self::$bots;
    }

    /**
     * Fast-fail user-agent pre-check (single stripos pass)
     *
     * Cheap screening used on every frontend request before any regex work.
     * Returns true when the UA could possibly belong to a known bot.
     *
     * @param string $user_agent Raw user agent string
     * @return bool
     */
    public static function matches_ua($user_agent) {
        if (!is_string($user_agent) || $user_agent === '') {
            return false;
        }

        foreach (self::$ua_needles as $needle) {
            if (stripos($user_agent, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get user-agent regex patterns keyed by bot name (detection order preserved)
     *
     * @return array
     */
    public static function get_ua_patterns() {
        $patterns = [];
        foreach (self::$bots as $name => $bot) {
            if (!empty($bot['ua_pattern'])) {
                $patterns[$name] = $bot['ua_pattern'];
            }
        }
        return $patterns;
    }

    /**
     * Identify an LLM bot from a user agent string
     *
     * @param string $user_agent User agent string
     * @return string|false Bot name or false if not a known LLM bot
     */
    public static function identify_from_ua($user_agent) {
        foreach (self::get_ua_patterns() as $name => $pattern) {
            if (preg_match($pattern, $user_agent)) {
                return $name;
            }
        }
        return false;
    }

    /**
     * Get utm_source regex pattern bodies keyed by bot name
     *
     * @return array
     */
    public static function get_utm_patterns() {
        $patterns = [];
        foreach (self::$bots as $name => $bot) {
            if (!empty($bot['utm_pattern'])) {
                $patterns[$name] = $bot['utm_pattern'];
            }
        }
        return $patterns;
    }

    /**
     * Get bot names that should be pre-seeded in the bot clicks summary table
     *
     * @return array
     */
    public static function get_click_table_bot_names() {
        $names = [];
        foreach (self::$bots as $name => $bot) {
            if (!empty($bot['click_row'])) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Get bot name => type classification map
     *
     * @return array
     */
    public static function get_bot_type_map() {
        $map = [];
        foreach (self::$bots as $name => $bot) {
            $map[$name] = $bot['type'];
        }
        return $map;
    }

    /**
     * Get the type for a single bot name
     *
     * @param string $bot_name Bot name
     * @return string Bot type (unknown when not registered)
     */
    public static function get_bot_type($bot_name) {
        return isset(self::$bots[$bot_name]) ? self::$bots[$bot_name]['type'] : 'unknown';
    }

    /**
     * Get bot type score multipliers used by the visibility score
     *
     * @return array
     */
    public static function get_bot_type_multipliers() {
        return [
            'user_search_ai' => 1.0,
            'crawler_ai'     => 0.6,
            'unknown'        => 0.2,
        ];
    }
}
