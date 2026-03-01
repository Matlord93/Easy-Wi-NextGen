<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MailLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MailLogRepository::class)]
#[ORM\Table(name: 'mail_logs')]
#[ORM\Index(name: 'idx_mail_logs_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_mail_logs_domain_created_at', columns: ['domain_id', 'created_at'])]
#[ORM\Index(name: 'idx_mail_logs_level_created_at', columns: ['level', 'created_at'])]
class MailLog
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_CRITICAL = 'critical';

    public const SOURCE_POSTFIX = 'postfix';
    public const SOURCE_DOVECOT = 'dovecot';
    public const SOURCE_OPENDKIM = 'opendkim';
    public const SOURCE_AGENT = 'agent';
    public const SOURCE_DNS = 'dns';
    public const SOURCE_RSPAMD = 'rspamd';

    public const EVENT_DELIVERY = 'delivery';
    public const EVENT_AUTH = 'auth';
    public const EVENT_TLS = 'tls';
    public const EVENT_SPAM = 'spam';
    public const EVENT_BOUNCE = 'bounce';
    public const EVENT_DNS_CHECK = 'dns_check';
    public const EVENT_QUEUE = 'queue';
    public const EVENT_POLICY = 'policy';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[Assert\Choice(choices: [self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR, self::LEVEL_CRITICAL])]
    #[ORM\Column(length: 16)]
    private string $level;

    #[Assert\Choice(choices: [self::SOURCE_POSTFIX, self::SOURCE_DOVECOT, self::SOURCE_OPENDKIM, self::SOURCE_AGENT, self::SOURCE_DNS, self::SOURCE_RSPAMD])]
    #[ORM\Column(length: 32)]
    private string $source;

    #[Assert\NotBlank]
    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Domain $domain;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[Assert\Choice(choices: [self::EVENT_DELIVERY, self::EVENT_AUTH, self::EVENT_TLS, self::EVENT_SPAM, self::EVENT_BOUNCE, self::EVENT_DNS_CHECK, self::EVENT_QUEUE, self::EVENT_POLICY])]
    #[ORM\Column(length: 32)]
    private string $eventType;

    /** @var array<string,mixed> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $payload = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param array<string,mixed> $payload */
    public function __construct(Domain $domain, string $level, string $source, string $eventType, string $message, array $payload = [], ?User $user = null)
    {
        $this->domain = $domain;
        $this->level = self::normalizeLevel($level);
        $this->source = self::normalizeSource($source);
        $this->eventType = self::normalizeEventType($eventType);
        $this->message = trim($message);
        $this->payload = $payload;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getDomain(): Domain
    {
        return $this->domain;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    /** @return array<string,mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    private static function normalizeLevel(string $value): string
    {
        $v = strtolower(trim($value));
        return in_array($v, [self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR, self::LEVEL_CRITICAL], true) ? $v : self::LEVEL_INFO;
    }

    private static function normalizeSource(string $value): string
    {
        $v = strtolower(trim($value));
        return in_array($v, [self::SOURCE_POSTFIX, self::SOURCE_DOVECOT, self::SOURCE_OPENDKIM, self::SOURCE_AGENT, self::SOURCE_DNS, self::SOURCE_RSPAMD], true) ? $v : self::SOURCE_AGENT;
    }

    private static function normalizeEventType(string $value): string
    {
        $v = strtolower(trim($value));
        return in_array($v, [self::EVENT_DELIVERY, self::EVENT_AUTH, self::EVENT_TLS, self::EVENT_SPAM, self::EVENT_BOUNCE, self::EVENT_DNS_CHECK, self::EVENT_QUEUE, self::EVENT_POLICY], true) ? $v : self::EVENT_POLICY;
    }
}
