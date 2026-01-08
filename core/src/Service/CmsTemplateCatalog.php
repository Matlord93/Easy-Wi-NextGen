<?php

declare(strict_types=1);

namespace App\Service;

final class CmsTemplateCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTemplates(): array
    {
        return [
            $this->hostingTemplate(),
            $this->privateTemplate(),
            $this->clanTemplate(),
        ];
    }

    public function getTemplate(string $key): ?array
    {
        foreach ($this->listTemplates() as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function hostingTemplate(): array
    {
        return [
            'key' => 'hosting',
            'label' => 'Hosting Komplett',
            'description' => 'Alles für ein modernes Hosting-Portal: Startseite, Produkte, Preise, Status, Support und FAQs.',
            'pages' => [
                $this->page('Startseite', 'startseite', [
                    $this->block('text', $this->hostingHero()),
                    $this->block('text', $this->hostingFeatureGrid()),
                    $this->block('text', $this->hostingTrust()),
                    $this->block('text', $this->hostingCta()),
                ]),
                $this->page('Hosting-Produkte', 'hosting', [
                    $this->block('text', $this->hostingProducts()),
                    $this->block('text', $this->hostingHighlights()),
                ]),
                $this->page('Game-Server', 'gameserver', [
                    $this->block('text', $this->gameserverHero()),
                    $this->block('server_list', $this->serverListSettings()),
                    $this->block('text', $this->gameserverFeatures()),
                ]),
                $this->page('Webhosting', 'webhosting', [
                    $this->block('text', $this->webhostingHero()),
                    $this->block('text', $this->webhostingFeatures()),
                ]),
                $this->page('Preise', 'preise', [
                    $this->block('text', $this->pricingIntro()),
                    $this->block('text', $this->pricingGrid()),
                    $this->block('text', $this->pricingFaq()),
                ]),
                $this->page('Status', 'status', [
                    $this->block('text', $this->statusOverview()),
                ]),
                $this->page('Support', 'support', [
                    $this->block('text', $this->supportHero()),
                    $this->block('text', $this->supportChannels()),
                ]),
                $this->page('FAQ', 'faq', [
                    $this->block('text', $this->faqBlocks()),
                ]),
                $this->page('Kontakt', 'kontakt', [
                    $this->block('text', $this->contactCard()),
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function privateTemplate(): array
    {
        return [
            'key' => 'private',
            'label' => 'Privat Minimal',
            'description' => 'Schlankes Setup für private Seiten: Startseite, Über mich und Kontakt.',
            'pages' => [
                $this->page('Startseite', 'startseite', [
                    $this->block('text', $this->privateHero()),
                    $this->block('text', $this->privateHighlights()),
                ]),
                $this->page('Über mich', 'ueber-mich', [
                    $this->block('text', $this->privateAbout()),
                ]),
                $this->page('Kontakt', 'kontakt', [
                    $this->block('text', $this->contactCard()),
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clanTemplate(): array
    {
        return [
            'key' => 'clan',
            'label' => 'Clan Modern',
            'description' => 'Fokus auf Teams, Events, Recruiting und Server – modern, klar und ohne Ballast.',
            'pages' => [
                $this->page('Startseite', 'startseite', [
                    $this->block('text', $this->clanHero()),
                    $this->block('server_featured', $this->featuredServerSettings()),
                    $this->block('text', $this->clanStats()),
                    $this->block('text', $this->clanCta()),
                ]),
                $this->page('Teams', 'teams', [
                    $this->block('text', $this->clanTeams()),
                ]),
                $this->page('Events', 'events', [
                    $this->block('text', $this->clanEvents()),
                ]),
                $this->page('Server', 'server', [
                    $this->block('text', $this->clanServerIntro()),
                    $this->block('server_list', $this->serverListSettings()),
                ]),
                $this->page('Mitmachen', 'mitmachen', [
                    $this->block('text', $this->clanJoin()),
                ]),
                $this->page('Kontakt', 'kontakt', [
                    $this->block('text', $this->contactCard()),
                ]),
            ],
        ];
    }

    /**
     * @param array<int, array<string, string>> $blocks
     * @return array<string, mixed>
     */
    private function page(string $title, string $slug, array $blocks): array
    {
        return [
            'title' => $title,
            'slug' => $slug,
            'is_published' => true,
            'blocks' => $blocks,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function block(string $type, string $content): array
    {
        return [
            'type' => $type,
            'content' => $content,
        ];
    }

    private function serverListSettings(int $limit = 6): string
    {
        return json_encode([
            'game' => null,
            'limit' => $limit,
            'show_players' => true,
            'show_join_button' => true,
        ], JSON_THROW_ON_ERROR);
    }

    private function featuredServerSettings(): string
    {
        return json_encode([
            'game' => null,
            'limit' => 1,
            'show_players' => true,
            'show_join_button' => true,
        ], JSON_THROW_ON_ERROR);
    }

    private function hostingHero(): string
    {
        return <<<HTML
<section class="rounded-2xl bg-slate-900 px-8 py-10 text-white">
    <p class="text-sm uppercase tracking-[0.2em] text-slate-300">Next-Gen Hosting</p>
    <h2 class="mt-3 text-3xl font-semibold">Skalierbare Server, die sofort starten.</h2>
    <p class="mt-4 text-base text-slate-200">Gameserver, Webhosting und Datenbanken in einem Portal – modern, schnell und 24/7 betreut.</p>
    <div class="mt-6 flex flex-wrap gap-3">
        <span class="rounded-full bg-white/10 px-4 py-2 text-xs font-semibold">99,9% Uptime</span>
        <span class="rounded-full bg-white/10 px-4 py-2 text-xs font-semibold">NVMe Storage</span>
        <span class="rounded-full bg-white/10 px-4 py-2 text-xs font-semibold">DDoS-Schutz</span>
    </div>
</section>
HTML;
    }

    private function hostingFeatureGrid(): string
    {
        return <<<HTML
<section class="grid gap-4 md:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="text-lg font-semibold">Deploy in Minuten</h3>
        <p class="mt-2 text-sm text-slate-600">Templates, Auto-Updates und Snapshots machen den Start kinderleicht.</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="text-lg font-semibold">Performance-Stack</h3>
        <p class="mt-2 text-sm text-slate-600">Dedizierte Ressourcen, NVMe, Echtzeit-Monitoring und Edge-Routing.</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="text-lg font-semibold">Support 24/7</h3>
        <p class="mt-2 text-sm text-slate-600">Persönlicher Support, Migrationshilfe und Onboarding für dein Team.</p>
    </div>
</section>
HTML;
    }

    private function hostingTrust(): string
    {
        return <<<HTML
<section class="rounded-xl border border-slate-200 bg-slate-50 p-6">
    <div class="grid gap-6 md:grid-cols-3">
        <div>
            <p class="text-3xl font-semibold text-slate-900">500+</p>
            <p class="text-xs uppercase tracking-wide text-slate-500">aktive Projekte</p>
        </div>
        <div>
            <p class="text-3xl font-semibold text-slate-900">12 ms</p>
            <p class="text-xs uppercase tracking-wide text-slate-500">Ø Latenz EU</p>
        </div>
        <div>
            <p class="text-3xl font-semibold text-slate-900">4,9/5</p>
            <p class="text-xs uppercase tracking-wide text-slate-500">Kundenzufriedenheit</p>
        </div>
    </div>
</section>
HTML;
    }

    private function hostingCta(): string
    {
        return <<<HTML
<section class="rounded-xl border border-indigo-200 bg-indigo-50 p-6">
    <h3 class="text-xl font-semibold text-indigo-900">Bereit für den Launch?</h3>
    <p class="mt-2 text-sm text-indigo-800">Lege jetzt dein erstes Projekt an und starte in wenigen Minuten.</p>
</section>
HTML;
    }

    private function hostingProducts(): string
    {
        return <<<HTML
<section class="space-y-4">
    <h2 class="text-2xl font-semibold">Produkte, die mitwachsen</h2>
    <p class="text-sm text-slate-600">Modulare Pakete für jede Phase – vom MVP bis zum Enterprise-Cluster.</p>
    <ul class="grid gap-4 md:grid-cols-3">
        <li class="rounded-xl border border-slate-200 bg-white p-4">
            <h3 class="font-semibold">Game-Hosting</h3>
            <p class="mt-2 text-sm text-slate-600">Low-Latency Nodes, automatische Updates und Mod-Unterstützung.</p>
        </li>
        <li class="rounded-xl border border-slate-200 bg-white p-4">
            <h3 class="font-semibold">Webhosting</h3>
            <p class="mt-2 text-sm text-slate-600">Schnell skalierbar mit CDN, SSL und Staging-Umgebungen.</p>
        </li>
        <li class="rounded-xl border border-slate-200 bg-white p-4">
            <h3 class="font-semibold">Managed Datenbanken</h3>
            <p class="mt-2 text-sm text-slate-600">Backups, Monitoring und sichere Zugriffe ab Werk.</p>
        </li>
    </ul>
</section>
HTML;
    }

    private function hostingHighlights(): string
    {
        return <<<HTML
<section class="rounded-xl border border-slate-200 bg-white p-6">
    <h3 class="text-lg font-semibold">Highlights</h3>
    <div class="mt-4 grid gap-4 md:grid-cols-2">
        <div>
            <p class="text-sm font-semibold">Automatisierte Deployments</p>
            <p class="mt-2 text-sm text-slate-600">Templates, CI-Integration und Rollbacks.</p>
        </div>
        <div>
            <p class="text-sm font-semibold">Security-Stack</p>
            <p class="mt-2 text-sm text-slate-600">WAF, DDoS-Schutz und tägliche Backups.</p>
        </div>
    </div>
</section>
HTML;
    }

    private function gameserverHero(): string
    {
        return <<<HTML
<section class="rounded-xl border border-slate-200 bg-white p-6">
    <h2 class="text-2xl font-semibold">Game-Server in Bestform</h2>
    <p class="mt-3 text-sm text-slate-600">Schnell startklar, stabil unter Last und mit Tools für dein Team.</p>
</section>
HTML;
    }

    private function gameserverFeatures(): string
    {
        return <<<HTML
<section class="grid gap-4 md:grid-cols-2">
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="font-semibold">Instant Modpacks</h3>
        <p class="mt-2 text-sm text-slate-600">Vorkonfigurierte Mods und One-Click Installationen.</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="font-semibold">Spieler-Analytics</h3>
        <p class="mt-2 text-sm text-slate-600">Live-Statistiken, Performance-Checks und Alerts.</p>
    </div>
</section>
HTML;
    }

    private function webhostingHero(): string
    {
        return <<<HTML
<section class="rounded-xl border border-slate-200 bg-white p-6">
    <h2 class="text-2xl font-semibold">Webhosting für moderne Teams</h2>
    <p class="mt-3 text-sm text-slate-600">SSL, CDN, automatische Deployments und Staging inklusive.</p>
</section>
HTML;
    }

    private function webhostingFeatures(): string
    {
        return <<<HTML
<section class="grid gap-4 md:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="font-semibold">Edge-Caching</h3>
        <p class="mt-2 text-sm text-slate-600">Globale Performance mit smartem Cache.</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="font-semibold">Staging</h3>
        <p class="mt-2 text-sm text-slate-600">Testen bevor es live geht.</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="font-semibold">Backup & Restore</h3>
        <p class="mt-2 text-sm text-slate-600">Tägliche Backups mit 1-Klick Restore.</p>
    </div>
</section>
HTML;
    }

    private function pricingIntro(): string
    {
        return <<<HTML
<section class="rounded-xl border border-slate-200 bg-white p-6">
    <h2 class="text-2xl font-semibold">Preise, die zu deinem Wachstum passen</h2>
    <p class="mt-3 text-sm text-slate-600">Transparent, monatlich kündbar und jederzeit skalierbar.</p>
</section>
HTML;
    }

    private function pricingGrid(): string
    {
        return <<<HTML
<section class="grid gap-4 md:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <p class="text-xs uppercase text-slate-500">Starter</p>
        <p class="mt-2 text-2xl font-semibold">ab 9€</p>
        <ul class="mt-3 text-sm text-slate-600 list-disc pl-4">
            <li>1 Projekt</li>
            <li>Basic Support</li>
            <li>Automatische Backups</li>
        </ul>
    </div>
    <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-5">
        <p class="text-xs uppercase text-indigo-600">Pro</p>
        <p class="mt-2 text-2xl font-semibold text-indigo-900">ab 29€</p>
        <ul class="mt-3 text-sm text-indigo-800 list-disc pl-4">
            <li>Bis 10 Projekte</li>
            <li>Priority Support</li>
            <li>Team-Zugänge</li>
        </ul>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <p class="text-xs uppercase text-slate-500">Enterprise</p>
        <p class="mt-2 text-2xl font-semibold">individuell</p>
        <ul class="mt-3 text-sm text-slate-600 list-disc pl-4">
            <li>Custom Infrastruktur</li>
            <li>SLA & Beratung</li>
            <li>Dedicated Support</li>
        </ul>
    </div>
</section>
HTML;
    }

    private function pricingFaq(): string
    {
        return <<<HTML
<section class="rounded-xl border border-slate-200 bg-white p-6">
    <h3 class="text-lg font-semibold">Häufige Fragen</h3>
    <div class="mt-4 space-y-3 text-sm text-slate-600">
        <p><strong>Kann ich jederzeit upgraden?</strong> Ja, Upgrades sind jederzeit möglich.</p>
        <p><strong>Gibt es Rabatte?</strong> Jahresverträge werden individuell rabattiert.</p>
    </div>
</section>
HTML;
    }

    private function statusOverview(): string
    {
        return <<<HTML
<section class="rounded-xl border border-slate-200 bg-white p-6">
    <h2 class="text-2xl font-semibold">Status & Wartung</h2>
    <p class="mt-3 text-sm text-slate-600">Hier findest du Live-Updates zu Verfügbarkeit, Wartungsfenstern und Vorfällen.</p>
</section>
HTML;
    }

    private function supportHero(): string
    {
        return <<<HTML
<section class="rounded-xl border border-slate-200 bg-white p-6">
    <h2 class="text-2xl font-semibold">Support, der wirklich hilft</h2>
    <p class="mt-3 text-sm text-slate-600">Direkt erreichbar, technisch stark und mit klaren SLAs.</p>
</section>
HTML;
    }

    private function supportChannels(): string
    {
        return <<<HTML
<section class="grid gap-4 md:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="font-semibold">Live-Chat</p>
        <p class="mt-2 text-sm text-slate-600">Schnelle Hilfe für kritische Themen.</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="font-semibold">Ticket-System</p>
        <p class="mt-2 text-sm text-slate-600">Strukturiert und nachvollziehbar.</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="font-semibold">Knowledge Base</p>
        <p class="mt-2 text-sm text-slate-600">Anleitungen, FAQs und Troubleshooting.</p>
    </div>
</section>
HTML;
    }

    private function faqBlocks(): string
    {
        return <<<HTML
<section class="space-y-4">
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="font-semibold">Wie schnell ist der Support?</h3>
        <p class="mt-2 text-sm text-slate-600">Je nach Paket innerhalb von Minuten bis max. 24 Stunden.</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="font-semibold">Kann ich meine Daten migrieren?</h3>
        <p class="mt-2 text-sm text-slate-600">Ja, wir unterstützen dich beim Umzug.</p>
    </div>
</section>
HTML;
    }

    private function privateHero(): string
    {
        return <<<HTML
<section class="rounded-2xl bg-slate-900 px-8 py-10 text-white">
    <p class="text-xs uppercase tracking-[0.2em] text-slate-300">Privat</p>
    <h2 class="mt-3 text-3xl font-semibold">Willkommen auf meiner Seite.</h2>
    <p class="mt-4 text-base text-slate-200">Ein kurzer Überblick über mich, meine Projekte und wie du mich erreichst.</p>
</section>
HTML;
    }

    private function privateHighlights(): string
    {
        return <<<HTML
<section class="grid gap-4 md:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="font-semibold">Projekte</p>
        <p class="mt-2 text-sm text-slate-600">Ausgewählte Arbeiten und Experimente.</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="font-semibold">Skills</p>
        <p class="mt-2 text-sm text-slate-600">Design, Code und kreative Konzepte.</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="font-semibold">Updates</p>
        <p class="mt-2 text-sm text-slate-600">Was gerade entsteht und als Nächstes kommt.</p>
    </div>
</section>
HTML;
    }

    private function privateAbout(): string
    {
        return <<<HTML
<section class="rounded-xl border border-slate-200 bg-white p-6">
    <h2 class="text-2xl font-semibold">Kurz & persönlich</h2>
    <p class="mt-3 text-sm text-slate-600">Erzähl hier in ein paar Sätzen, wer du bist, was dich antreibt und woran du arbeitest.</p>
</section>
HTML;
    }

    private function clanHero(): string
    {
        return <<<HTML
<section class="rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-900 to-slate-900 px-8 py-10 text-white">
    <p class="text-xs uppercase tracking-[0.2em] text-indigo-200">Clan Hub</p>
    <h2 class="mt-3 text-3xl font-semibold">Wir spielen. Wir gewinnen. Gemeinsam.</h2>
    <p class="mt-4 text-base text-indigo-100">News, Events und Server-Infos an einem Ort.</p>
</section>
HTML;
    }

    private function clanStats(): string
    {
        return <<<HTML
<section class="rounded-xl border border-slate-200 bg-white p-6">
    <div class="grid gap-6 md:grid-cols-3">
        <div>
            <p class="text-3xl font-semibold text-slate-900">25+</p>
            <p class="text-xs uppercase text-slate-500">aktive Member</p>
        </div>
        <div>
            <p class="text-3xl font-semibold text-slate-900">4</p>
            <p class="text-xs uppercase text-slate-500">Teams</p>
        </div>
        <div>
            <p class="text-3xl font-semibold text-slate-900">7</p>
            <p class="text-xs uppercase text-slate-500">Events / Monat</p>
        </div>
    </div>
</section>
HTML;
    }

    private function clanCta(): string
    {
        return <<<HTML
<section class="rounded-xl border border-indigo-200 bg-indigo-50 p-6">
    <h3 class="text-xl font-semibold text-indigo-900">Du willst mitmachen?</h3>
    <p class="mt-2 text-sm text-indigo-800">Sieh dir unsere Anforderungen an und stell dich kurz vor.</p>
</section>
HTML;
    }

    private function clanTeams(): string
    {
        return <<<HTML
<section class="grid gap-4 md:grid-cols-2">
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="font-semibold">Main Squad</h3>
        <p class="mt-2 text-sm text-slate-600">Core-Team für Turniere und Scrims.</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h3 class="font-semibold">Academy</h3>
        <p class="mt-2 text-sm text-slate-600">Rookies, die gemeinsam besser werden.</p>
    </div>
</section>
HTML;
    }

    private function clanEvents(): string
    {
        return <<<HTML
<section class="space-y-4">
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="font-semibold">Weekly Practice</h3>
        <p class="mt-2 text-sm text-slate-600">Jeden Mittwoch, 20:00 Uhr.</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="font-semibold">Community Night</h3>
        <p class="mt-2 text-sm text-slate-600">Offene Sessions für alle Member.</p>
    </div>
</section>
HTML;
    }

    private function clanServerIntro(): string
    {
        return <<<HTML
<section class="rounded-xl border border-slate-200 bg-white p-6">
    <h2 class="text-2xl font-semibold">Unsere Server</h2>
    <p class="mt-3 text-sm text-slate-600">Die wichtigsten Server auf einen Blick – inklusive Join-Link.</p>
</section>
HTML;
    }

    private function clanJoin(): string
    {
        return <<<HTML
<section class="rounded-xl border border-slate-200 bg-white p-6">
    <h2 class="text-2xl font-semibold">Mitmachen</h2>
    <p class="mt-3 text-sm text-slate-600">Erzähl uns kurz etwas über dich, deine Spiele und dein Zeitbudget.</p>
    <ul class="mt-4 list-disc pl-5 text-sm text-slate-600">
        <li>Mindestens 18 Jahre</li>
        <li>Teamplay & Discord</li>
        <li>Respektvoller Umgang</li>
    </ul>
</section>
HTML;
    }

    private function contactCard(): string
    {
        return <<<HTML
<section class="rounded-xl border border-slate-200 bg-white p-6">
    <h2 class="text-2xl font-semibold">Kontakt</h2>
    <p class="mt-3 text-sm text-slate-600">Schreib uns eine Nachricht – wir melden uns schnellstmöglich.</p>
    <div class="mt-4 text-sm text-slate-600">
        <p><strong>E-Mail:</strong> kontakt@example.com</p>
        <p><strong>Discord:</strong> discord.gg/deinserver</p>
    </div>
</section>
HTML;
    }
}
