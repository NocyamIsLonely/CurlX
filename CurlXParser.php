<?php

declare(strict_types=1);

class CurlXParser
{
    private function normalize(string $html): string
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = str_replace(["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"], ['"', '"', "'", "'"], $html);
        $html = preg_replace('/\s+/', ' ', $html);
        return $html;
    }

    private function tryBoth(string $html, callable $fn): mixed
    {
        $result = $fn($html);

        if (!empty($result)) {
            return $result;
        }

        $normalized = $this->normalize($html);
        if ($normalized !== $html) {
            return $fn($normalized);
        }

        return $result;
    }

    /**
     *   $fields = $Parser->form($checkout->body);
     *   $nonce  = $fields['woocommerce-process-checkout-nonce'];
     *   $postId = $fields['_wfacp_post_id'];
     */
    public function form(string $html, int $index = 0): array
    {
        return $this->tryBoth($html, function(string $body) use ($index) {
            preg_match_all('/<form[^>]*>(.*?)<\/form>/is', $body, $forms);
            $target = $forms[1][$index] ?? $body;
            return $this->extractFields($target);
        });
    }
    
    public function forms(string $html): array
    {
        return $this->tryBoth($html, function(string $body) {
            preg_match_all('/<form[^>]*>(.*?)<\/form>/is', $body, $matches);
            $result = [];
            foreach ($matches[1] as $formHtml) {
                $result[] = $this->extractFields($formHtml);
            }
            return $result;
        });
    }

    private function extractFields(string $html): array
    {
        $fields = [];
        preg_match_all('/<input[^>]*>/i', $html, $inputs);
        foreach ($inputs[0] as $input) {
            $name = $this->attr($input, 'name');
            if ($name === '') continue;
            $fields[$name] = $this->attr($input, 'value');
        }
        preg_match_all('/<textarea[^>]*>(.*?)<\/textarea>/is', $html, $textareas);
        foreach ($textareas[0] as $textarea) {
            $name = $this->attr($textarea, 'name');
            if ($name === '') continue;
            preg_match('/<textarea[^>]*>(.*?)<\/textarea>/is', $textarea, $m);
            $fields[$name] = trim(strip_tags($m[1] ?? ''));
        }
        preg_match_all('/<select[^>]*>(.*?)<\/select>/is', $html, $selects);
        foreach ($selects[0] as $select) {
            $name = $this->attr($select, 'name');
            if ($name === '') continue;
            if (preg_match('/<option[^>]*selected[^>]*value=["\']([^"\']*)["\'][^>]*>/i', $select, $m) ||
                preg_match('/<option[^>]*value=["\']([^"\']*)["\'][^>]*>/i', $select, $m)) {
                $fields[$name] = $m[1];
            }
        }

        return $fields;
    }

    /**
     *   $hidden = $Parser->hidden($checkout->body);
     *   $nonce  = $hidden['woocommerce-process-checkout-nonce'];
     */
    public function hidden(string $html): array
    {
        return $this->tryBoth($html, function(string $body) {
            $fields = [];
            preg_match_all(
                '/<input[^>]*type=["\']hidden["\'][^>]*>/i',
                $body,
                $inputs
            );

            foreach ($inputs[0] as $input) {
                $name  = $this->attr($input, 'name');
                $value = $this->attr($input, 'value');
                if ($name !== '') {
                    $fields[$name] = $value;
                }
            }

            return $fields;
        });
    }

    /**
     *   $nonce = $Parser->field($checkout->body, 'woocommerce-process-checkout-nonce');
     *   $token = $Parser->field($html, '_token');
     */
    public function field(string $html, string $name): string
    {
        $escaped = preg_quote($name, '/');

        return $this->tryBoth($html, function(string $body) use ($name, $escaped) {
            $pattern = '/<input[^>]*name=["\']' . $escaped . '["\'][^>]*>/i';
            if (preg_match($pattern, $body, $m)) {
                $value = $this->attr($m[0], 'value');
                if ($value !== '' || strpos($m[0], 'value=') !== false) {
                    return $value;
                }
            }
            $pattern = '/<textarea[^>]*name=["\']' . $escaped . '["\'][^>]*>(.*?)<\/textarea>/is';
            if (preg_match($pattern, $body, $m)) {
                return trim(strip_tags($m[1]));
            }

            return '';
        });
    }

    /**
     * Retorna null se não encontrar nenhum captcha
     *
     *   $captcha = $Parser->detectCaptcha($checkout->body);
     *   if ($captcha) {
     *       echo $captcha['type'];    // 'recaptcha_v2'
     *       echo $captcha['sitekey']; // '6Ldtnw0oAAAA...'
     *       $token = $Bypass->recaptchaV2($captcha['sitekey'], $url);
     *   }
     */
    public function detectCaptcha(string $html): ?array
    {
        $body = $this->normalize($html);
        $turnstilePatterns = [
            '/data-sitekey=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*cf-turnstile/i',
            '/class=["\'][^"\']*cf-turnstile[^"\']*["\'][^>]*data-sitekey=["\']([^"\']+)["\']/i',
            '/cf-turnstile[^>]*data-sitekey=["\']([^"\']+)["\']/i',
            '/turnstile[^>]*sitekey["\s:=\']+([a-zA-Z0-9_\-]{20,})/i',
        ];

        foreach ($turnstilePatterns as $p) {
            if (preg_match($p, $body, $m)) {
                return ['type' => 'turnstile', 'sitekey' => $m[1]];
            }
        }

        $hcaptchaPatterns = [
            '/data-sitekey=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*h-captcha/i',
            '/class=["\'][^"\']*h-captcha[^"\']*["\'][^>]*data-sitekey=["\']([^"\']+)["\']/i',
            '/hcaptcha\.com[^>]*data-sitekey=["\']([^"\']+)["\']/i',
            '/hcaptcha[^>]*sitekey["\s:=\']+([a-zA-Z0-9_\-]{20,})/i',
        ];

        foreach ($hcaptchaPatterns as $p) {
            if (preg_match($p, $body, $m)) {
                return ['type' => 'hcaptcha', 'sitekey' => $m[1]];
            }
        }

        $v3Patterns = [
            '/grecaptcha\.execute\(["\']([^"\']+)["\']/i',
            '/recaptcha\/api\.js\?render=([a-zA-Z0-9_\-]+)/i',
            '/grecaptcha\.ready.*?execute\(["\']([^"\']+)["\']/is',
        ];

        foreach ($v3Patterns as $p) {
            if (preg_match($p, $body, $m)) {
                $action = '';
                if (preg_match('/action["\s:=\']+["\']([^"\']+)["\']/i', $body, $am)) {
                    $action = $am[1];
                }
                return ['type' => 'recaptcha_v3', 'sitekey' => $m[1], 'action' => $action];
            }
        }

        $v2Patterns = [
            '/class=["\'][^"\']*g-recaptcha[^"\']*["\'][^>]*data-sitekey=["\']([^"\']+)["\']/i',
            '/data-sitekey=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*g-recaptcha/i',
            '/data-sitekey=["\']([6][a-zA-Z0-9_\-]{39})["\']/i',
            '/grecaptcha\.render\([^)]*["\']sitekey["\']\s*:\s*["\']([^"\']+)["\']/i',
            '/recaptcha[^>]*data-sitekey=["\']([^"\']+)["\']/i',
        ];

        foreach ($v2Patterns as $p) {
            if (preg_match($p, $body, $m)) {
                return ['type' => 'recaptcha_v2', 'sitekey' => $m[1]];
            }
        }

        return null;
    }

    /**
     * Extrai todos os links da página
     *
     *   $links = $Parser->links($html);
     *   // ['https://site.com/page1', 'https://site.com/page2', ...]
     *
     *   // Filtra por padrão
     *   $links = $Parser->links($html, 'produto');
     */
    public function links(string $html, string $filter = ''): array
    {
        return $this->tryBoth($html, function(string $body) use ($filter) {
            preg_match_all('/<a[^>]+href=["\']([^"\'#][^"\']*)["\'][^>]*>/i', $body, $m);
            $links = array_unique($m[1] ?? []);

            if ($filter !== '') {
                $links = array_values(array_filter($links, fn($l) => str_contains($l, $filter)));
            }

            return $links;
        });
    }

    /**
     *   $Parser->link($html, 'Próxima página')
     *   $Parser->link($html, 'checkout')  // procura no href também
     */
    public function link(string $html, string $needle): string
    {
        return $this->tryBoth($html, function(string $body) use ($needle) {
            $pattern = '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>[^<]*' . preg_quote($needle, '/') . '[^<]*<\/a>/i';
            if (preg_match($pattern, $body, $m)) {
                return $m[1];
            }
            $pattern = '/<a[^>]+href=["\']([^"\']*' . preg_quote($needle, '/') . '[^"\']*)["\'][^>]*>/i';
            if (preg_match($pattern, $body, $m)) {
                return $m[1];
            }

            return '';
        });
    }
    
    /**
     *
     *   $Parser->meta($html, 'csrf-token')
     *   $Parser->meta($html, 'og:title')
     */
    public function meta(string $html, string $name): string
    {
        return $this->tryBoth($html, function(string $body) use ($name) {
            $escaped = preg_quote($name, '/');

            $patterns = [
                '/<meta[^>]*name=["\']' . $escaped . '["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/i',
                '/<meta[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']' . $escaped . '["\'][^>]*>/i',
                '/<meta[^>]*property=["\']' . $escaped . '["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/i',
                '/<meta[^>]*content=["\']([^"\']*)["\'][^>]*property=["\']' . $escaped . '["\'][^>]*>/i',
            ];

            foreach ($patterns as $p) {
                if (preg_match($p, $body, $m)) {
                    return $m[1];
                }
            }

            return '';
        });
    }

    public function title(string $html): string
    {
        return $this->tryBoth($html, function(string $body) {
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) {
                return trim(strip_tags($m[1]));
            }
            return '';
        });
    }

    public function jsVar(string $html, string $varName): string
    {
        return $this->tryBoth($html, function(string $body) use ($varName) {
            $escaped = preg_quote($varName, '/');

            $patterns = [
                '/(?:var|let|const)\s+' . $escaped . '\s*=\s*["\']([^"\']+)["\']/i',
                '/' . $escaped . '\s*=\s*["\']([^"\']+)["\']/i',
                '/["\']?' . $escaped . '["\']?\s*:\s*["\']([^"\']+)["\']/i',
                '/(?:var|let|const)\s+' . $escaped . '\s*=\s*`([^`]+)`/i',
            ];

            foreach ($patterns as $p) {
                if (preg_match($p, $body, $m)) {
                    return trim($m[1]);
                }
            }

            return '';
        });
    }

    public function table(string $html, int $index = 0): array
    {
        return $this->tryBoth($html, function(string $body) use ($index) {
            preg_match_all('/<table[^>]*>(.*?)<\/table>/is', $body, $tables);

            if (!isset($tables[1][$index])) {
                return [];
            }

            $rows = [];
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tables[1][$index], $trs);

            foreach ($trs[1] as $tr) {
                preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $tr, $cells);
                $row = array_map(fn($c) => trim(strip_tags($c)), $cells[1]);
                if (!empty(array_filter($row))) {
                    $rows[] = $row;
                }
            }

            return $rows;
        });
    }

    public function attr(string $tag, string $attr): string
    {
        $escaped = preg_quote($attr, '/');

        $patterns = [
            '/' . $escaped . '=["\']([^"\']*)["\']/',
            '/' . $escaped . '=([^\s>]+)/',
        ];

        foreach ($patterns as $p) {
            if (preg_match($p, $tag, $m)) {
                return $m[1];
            }
        }

        return '';
    }
    
    public function find(string $html, string $selector): array
    {
        return $this->tryBoth($html, function(string $body) use ($selector) {
            $tag   = 'div';
            $class = '';
            $id    = '';

            if (preg_match('/^([a-z0-9]+)?(?:\.([a-z0-9_\-]+))?(?:#([a-z0-9_\-]+))?$/i', $selector, $m)) {
                $tag   = $m[1] ?: '[a-z]+';
                $class = $m[2] ?? '';
                $id    = $m[3] ?? '';
            }

            $attrPattern = '';
            if ($class) $attrPattern .= '(?=[^>]*class=["\'][^"\']*' . preg_quote($class, '/') . '[^"\']*["\'])';
            if ($id)    $attrPattern .= '(?=[^>]*id=["\']' . preg_quote($id, '/') . '["\'])';

            $pattern = '/<' . $tag . $attrPattern . '[^>]*>(.*?)<\/' . $tag . '>/is';

            preg_match_all($pattern, $body, $matches);
            return $matches[1] ?? [];
        });
    }
}
