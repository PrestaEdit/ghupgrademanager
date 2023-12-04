<?php
declare(strict_types=1);

namespace PrestaShop\Module\GitHubUpgradeManager\Middleware;

class CachedResponse
{
    /** @var string[][] */
    private $headers;

    /** @var string */
    private $body;

    /**
     * @param string[][] $headers
     * @param string $body
     */
    public function __construct(array $headers, string $body)
    {
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * @return string[][]
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
