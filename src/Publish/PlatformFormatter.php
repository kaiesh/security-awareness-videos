<?php

declare(strict_types=1);

namespace SecurityDrama\Publish;

final class PlatformFormatter
{
    /**
     * Platform constraints: [max_caption, max_title, title_support, hashtag_format]
     * hashtag_format: 'inline' = #hashtags in text, 'description' = in description only,
     *                 'none' = no hashtags, 'keywords' = use as keywords not hashtags
     */
    private const PLATFORM_CONSTRAINTS = [
        'youtube'   => ['max_description' => 5000, 'max_title' => 100, 'title_support' => true,  'hashtag_format' => 'description'],
        'x'         => ['max_description' => 280,  'max_title' => 0,   'title_support' => false, 'hashtag_format' => 'inline'],
        'reddit'    => ['max_description' => 300,  'max_title' => 300, 'title_support' => true,  'hashtag_format' => 'none'],
        'instagram' => ['max_description' => 2200, 'max_title' => 0,   'title_support' => false, 'hashtag_format' => 'inline'],
        'facebook'  => ['max_description' => 63206,'max_title' => 0,   'title_support' => true,  'hashtag_format' => 'inline'],
        'tiktok'    => ['max_description' => 4000, 'max_title' => 0,   'title_support' => false, 'hashtag_format' => 'inline'],
        'linkedin'  => ['max_description' => 3000, 'max_title' => 0,   'title_support' => false, 'hashtag_format' => 'inline'],
        'threads'   => ['max_description' => 500,  'max_title' => 0,   'title_support' => false, 'hashtag_format' => 'inline'],
        'bluesky'   => ['max_description' => 300,  'max_title' => 0,   'title_support' => false, 'hashtag_format' => 'none'],
        'mastodon'  => ['max_description' => 500,  'max_title' => 0,   'title_support' => false, 'hashtag_format' => 'inline'],
        'pinterest' => ['max_description' => 500,  'max_title' => 100, 'title_support' => true,  'hashtag_format' => 'keywords'],
    ];

    /**
     * Format content for a specific platform.
     *
     * @param string $platform   Platform identifier
     * @param array  $scriptData Script record with title_youtube, title_social, description_youtube, hook_line, hashtags
     * @return array{title: string, caption: string, description: string, hashtag_string: string}
     */
    public function format(string $platform, array $scriptData): array
    {
        $constraints = self::PLATFORM_CONSTRAINTS[$platform] ?? self::PLATFORM_CONSTRAINTS['facebook'];

        $titleYoutube = $scriptData['title_youtube'] ?? '';
        $titleSocial  = $scriptData['title_social'] ?? $titleYoutube;
        $description  = $scriptData['description_youtube'] ?? '';
        $hookLine     = $scriptData['hook_line'] ?? '';
        $hashtags     = $this->parseHashtags($scriptData['hashtags'] ?? '');

        $hashtagString = $this->buildHashtagString($hashtags, $constraints['hashtag_format']);

        $result = match ($platform) {
            'youtube'   => $this->formatYouTube($titleYoutube, $description, $hashtagString, $constraints),
            'x'         => $this->formatShortCaption($hookLine, $titleSocial, $hashtagString, $constraints),
            'reddit'    => $this->formatReddit($titleSocial, $titleYoutube, $description, $constraints),
            'bluesky'   => $this->formatShortCaption($hookLine, $titleSocial, '', $constraints),
            'pinterest' => $this->formatPinterest($titleSocial, $description, $hashtags, $constraints),
            default     => $this->formatGeneric($hookLine, $titleSocial, $description, $hashtagString, $constraints),
        };

        return $result;
    }

    private function formatYouTube(string $title, string $description, string $hashtagString, array $constraints): array
    {
        $title = $this->truncate($title, $constraints['max_title']);

        $desc = $description;
        if ($hashtagString !== '') {
            $desc .= "\n\n" . $hashtagString;
        }
        $desc = $this->truncate($desc, $constraints['max_description']);

        return [
            'title'          => $title,
            'caption'        => '',
            'description'    => $desc,
            'hashtag_string' => $hashtagString,
        ];
    }

    private function formatShortCaption(string $hookLine, string $titleSocial, string $hashtagString, array $constraints): array
    {
        $text = $hookLine !== '' ? $hookLine : $titleSocial;

        if ($hashtagString !== '') {
            $available = $constraints['max_description'] - strlen($hashtagString) - 2;
            $text = $this->truncate($text, max(0, $available));
            $text .= "\n\n" . $hashtagString;
        }

        $text = $this->truncate($text, $constraints['max_description']);

        return [
            'title'          => '',
            'caption'        => $text,
            'description'    => '',
            'hashtag_string' => $hashtagString,
        ];
    }

    private function formatReddit(string $titleSocial, string $titleYoutube, string $description, array $constraints): array
    {
        $title = $titleSocial !== '' ? $titleSocial : $titleYoutube;
        $title = $this->truncate($title, $constraints['max_title']);
        $desc = $this->truncate($description, $constraints['max_description']);

        return [
            'title'          => $title,
            'caption'        => '',
            'description'    => $desc,
            'hashtag_string' => '',
        ];
    }

    private function formatPinterest(string $titleSocial, string $description, array $hashtags, array $constraints): array
    {
        $title = $this->truncate($titleSocial, $constraints['max_title']);
        $desc = $this->truncate($description, $constraints['max_description']);

        // Pinterest uses keywords, not hashtag format
        $keywords = implode(', ', $hashtags);

        return [
            'title'          => $title,
            'caption'        => '',
            'description'    => $desc,
            'hashtag_string' => $keywords,
        ];
    }

    private function formatGeneric(string $hookLine, string $titleSocial, string $description, string $hashtagString, array $constraints): array
    {
        $caption = $hookLine !== '' ? $hookLine : $titleSocial;

        if ($description !== '') {
            $caption .= "\n\n" . $description;
        }

        if ($hashtagString !== '') {
            $caption .= "\n\n" . $hashtagString;
        }

        $caption = $this->truncate($caption, $constraints['max_description']);

        $title = '';
        if ($constraints['title_support'] && $constraints['max_title'] > 0) {
            $title = $this->truncate($titleSocial, $constraints['max_title']);
        }

        return [
            'title'          => $title,
            'caption'        => $caption,
            'description'    => '',
            'hashtag_string' => $hashtagString,
        ];
    }

    /**
     * Parse hashtags from a JSON array string or comma-separated string.
     *
     * @return string[] Raw hashtag words without '#'
     */
    private function parseHashtags(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $tags = $decoded;
        } else {
            $tags = array_map('trim', explode(',', $raw));
        }

        return array_filter(array_map(function (string $tag): string {
            return ltrim(trim($tag), '#');
        }, $tags));
    }

    private function buildHashtagString(array $hashtags, string $format): string
    {
        if (empty($hashtags)) {
            return '';
        }

        return match ($format) {
            'inline', 'description' => implode(' ', array_map(fn(string $tag) => "#{$tag}", $hashtags)),
            'none', 'keywords'      => '',
        };
    }

    private function truncate(string $text, int $maxLength): string
    {
        if ($maxLength <= 0) {
            return $text;
        }

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3) . '...';
    }
}
