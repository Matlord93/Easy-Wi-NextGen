<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627190000 extends AbstractMigration
{
    public function getDescription(): string { return 'Seed global webradio catalog with well-known stations across genres and countries.'; }

    public function up(Schema $schema): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($this->stations() as $s) {
            $tags = json_encode($s['tags'] ?? [], JSON_THROW_ON_ERROR);
            $this->addSql(
                'INSERT INTO musicbot_radio_stations
                    (customer_id, instance_id, name, stream_url, resolved_stream_url, genre, description, homepage, logo_url, country, language, tags, bitrate, format, is_global, is_active, is_favorite, last_played_at, last_checked_at, metadata, created_at, updated_at)
                 VALUES
                    (NULL, NULL, :name, :stream_url, NULL, :genre, :description, :homepage, :logo_url, :country, :language, :tags, :bitrate, :format, 1, 1, 0, NULL, NULL, :metadata, :created_at, :updated_at)',
                [
                    'name'        => $s['name'],
                    'stream_url'  => $s['stream_url'],
                    'genre'       => $s['genre'] ?? null,
                    'description' => $s['description'] ?? null,
                    'homepage'    => $s['homepage'] ?? null,
                    'logo_url'    => $s['logo_url'] ?? null,
                    'country'     => $s['country'] ?? null,
                    'language'    => $s['language'] ?? null,
                    'tags'        => $tags,
                    'bitrate'     => $s['bitrate'] ?? null,
                    'format'      => $s['format'] ?? null,
                    'metadata'    => '{}',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM musicbot_radio_stations WHERE is_global = 1 AND customer_id IS NULL");
    }

    /** @return array<int, array<string, mixed>> */
    private function stations(): array
    {
        return [
            // ---- Rock / Metal -----------------------------------------------
            ['name' => 'Rock Antenne', 'stream_url' => 'https://stream.rockantenne.de/rockantenne/stream/mp3', 'genre' => 'Rock', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.rockantenne.de', 'description' => 'Der härteste Rock aus Bayern.', 'tags' => ['rock', 'classic rock', 'hard rock']],
            ['name' => 'Metal Only', 'stream_url' => 'https://streams.sevenmountainsmedia.com/metal-only/mp3-128', 'genre' => 'Metal', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.metal-only.de', 'description' => 'Pure Metal – 24/7.', 'tags' => ['metal', 'heavy metal', 'death metal']],
            ['name' => 'Planet Rock (UK)', 'stream_url' => 'https://stream-mz.planetradio.co.uk/planetrock.mp3', 'genre' => 'Rock', 'country' => 'UK', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.planetrock.com', 'description' => 'Britain\'s classic and contemporary rock station.', 'tags' => ['rock', 'classic rock']],
            ['name' => 'Radio Bob!', 'stream_url' => 'https://streams.radiobob.de/bob-national/mp3-128/radiobob', 'genre' => 'Rock', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.radiobob.de', 'description' => 'Der Rocksender.', 'tags' => ['rock', 'alternative', 'hard rock']],
            ['name' => 'Kerrang! Radio', 'stream_url' => 'https://stream.planetradio.co.uk/kerrangradio.mp3', 'genre' => 'Rock / Metal', 'country' => 'UK', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.kerrang.com', 'description' => 'Rock and metal hits 24/7.', 'tags' => ['rock', 'metal', 'alternative']],

            // ---- Pop --------------------------------------------------------
            ['name' => 'Antenne Bayern', 'stream_url' => 'https://stream.antenne.de/antenne', 'genre' => 'Pop', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.antenne.de', 'description' => 'Die Hitparade aus Bayern.', 'tags' => ['pop', 'hits', 'mainstream']],
            ['name' => 'Energy Berlin', 'stream_url' => 'https://energy-berlin.loverad.io/;', 'genre' => 'Pop / Dance', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.energy.de', 'description' => 'Non-Stop Dance & Pop.', 'tags' => ['pop', 'dance', 'club']],
            ['name' => 'RTL Radio', 'stream_url' => 'https://streams.rtlradio.de/rtlradio-real/mp3-128/stream', 'genre' => 'Pop / Schlager', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.rtlradio.de', 'description' => 'Schlager und Pop für ganz Deutschland.', 'tags' => ['schlager', 'pop']],
            ['name' => 'Radio NRJ France', 'stream_url' => 'https://scdn.nrj.fr/nrj_96_000', 'genre' => 'Pop', 'country' => 'Frankreich', 'language' => 'fr', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.nrj.fr', 'description' => 'La radio numéro 1 des hits.', 'tags' => ['pop', 'hits', 'francophone']],
            ['name' => 'BBC Radio 1', 'stream_url' => 'https://as-hls-ww-live.akamaized.net/pool_904/live/ww/bbc_radio_one/bbc_radio_one.isml/bbc_radio_one-audio%3d96000.norewind.m3u8', 'genre' => 'Pop / Dance', 'country' => 'UK', 'language' => 'en', 'bitrate' => 96, 'format' => 'aac', 'homepage' => 'https://www.bbc.co.uk/radio1', 'description' => 'New music from the UK and beyond.', 'tags' => ['pop', 'dance', 'uk', 'bbc']],

            // ---- Electronic / Dance -----------------------------------------
            ['name' => 'DI.FM Trance', 'stream_url' => 'https://stream.difm.listen.co/trance?&t=', 'genre' => 'Trance', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.di.fm', 'description' => 'Premier trance radio.', 'tags' => ['trance', 'edm', 'electronic']],
            ['name' => 'DI.FM Techno', 'stream_url' => 'https://stream.difm.listen.co/techno?&t=', 'genre' => 'Techno', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.di.fm', 'description' => 'Non-stop underground techno.', 'tags' => ['techno', 'edm', 'electronic']],
            ['name' => 'DI.FM Drum and Bass', 'stream_url' => 'https://stream.difm.listen.co/drumandbass?&t=', 'genre' => 'Drum & Bass', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.di.fm', 'description' => 'The best DnB tracks 24/7.', 'tags' => ['drum and bass', 'dnb', 'edm']],
            ['name' => 'DI.FM House', 'stream_url' => 'https://stream.difm.listen.co/deephouse?&t=', 'genre' => 'House', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.di.fm', 'description' => 'Deep house 24/7.', 'tags' => ['house', 'deep house', 'edm']],
            ['name' => 'BigFM', 'stream_url' => 'https://streams.bigfm.de/bigfm-deutschland-128-mp3?usid=0-0-H-A-D-30', 'genre' => 'Electronic / Hip Hop', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.bigfm.de', 'description' => 'Die neue Generation.', 'tags' => ['hip hop', 'edm', 'urban']],

            // ---- Jazz / Blues / Soul ----------------------------------------
            ['name' => 'Jazz24', 'stream_url' => 'https://live.wostreaming.net/direct/kuow-jazz24mp3-ibc1', 'genre' => 'Jazz', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.jazz24.org', 'description' => 'World class jazz, 24/7.', 'tags' => ['jazz', 'bebop', 'swing']],
            ['name' => 'SWR Jazz', 'stream_url' => 'https://liveradio.swr.de/sw282p3/swr1bw/', 'genre' => 'Jazz', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'aac', 'homepage' => 'https://www.swr.de/swr2/programm/jazz/', 'description' => 'Jazz aus dem SWR Programm.', 'tags' => ['jazz', 'swr', 'public radio']],
            ['name' => 'Blues Radio International', 'stream_url' => 'https://bluesradio.streamon.fm/yp-128', 'genre' => 'Blues', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://bluesradio.com', 'description' => '24/7 blues radio.', 'tags' => ['blues', 'delta blues', 'electric blues']],
            ['name' => 'Smooth Jazz Chicago', 'stream_url' => 'https://smoothjazz.cdnstream1.com/2585_128.mp3', 'genre' => 'Smooth Jazz', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://smoothjazz.com', 'description' => 'Chicago\'s smooth jazz.', 'tags' => ['smooth jazz', 'jazz', 'chillout']],

            // ---- Classical --------------------------------------------------
            ['name' => 'Klassik Radio', 'stream_url' => 'https://streams.klassikradio.de/klassikradio/mp3-128/stream.klassikradio.de', 'genre' => 'Klassik', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.klassikradio.de', 'description' => 'Klassische Musik non-stop.', 'tags' => ['klassik', 'classical', 'orchestral']],
            ['name' => 'BBC Radio 3', 'stream_url' => 'https://as-hls-ww-live.akamaized.net/pool_904/live/ww/bbc_radio_three/bbc_radio_three.isml/bbc_radio_three-audio%3d96000.norewind.m3u8', 'genre' => 'Classical', 'country' => 'UK', 'language' => 'en', 'bitrate' => 96, 'format' => 'aac', 'homepage' => 'https://www.bbc.co.uk/radio3', 'description' => 'Classical music, jazz and world.', 'tags' => ['classical', 'bbc', 'opera']],
            ['name' => 'ORF Ö1', 'stream_url' => 'https://oe1.orf.at/fm/8000/oe1_128.mp3', 'genre' => 'Klassik / Kultur', 'country' => 'Österreich', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://oe1.orf.at', 'description' => 'Österreichs Kultursender.', 'tags' => ['klassik', 'kultur', 'orf', 'öffentlich-rechtlich']],
            ['name' => 'Radio Swiss Classic', 'stream_url' => 'http://stream.srg-ssr.ch/rsc_de/mp3_128.m3u', 'genre' => 'Classical', 'country' => 'Schweiz', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.radioswissclassic.ch', 'description' => 'Classical music from Switzerland.', 'tags' => ['classical', 'srg', 'switzerland']],

            // ---- Hip Hop / R&B ----------------------------------------------
            ['name' => 'HipHop RadioWave', 'stream_url' => 'https://stream.radiowave.cz/hiphop', 'genre' => 'Hip Hop', 'country' => 'Tschechien', 'language' => 'cs', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://radiowave.cz', 'description' => 'Hip hop and urban music.', 'tags' => ['hip hop', 'rap', 'urban']],
            ['name' => 'Power 106 FM', 'stream_url' => 'https://icy-relay-01.power106fm.com/stream', 'genre' => 'Hip Hop / R&B', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.power106.fm', 'description' => 'LA\'s Hip Hop station.', 'tags' => ['hip hop', 'r&b', 'rap']],

            // ---- Country ----------------------------------------------------
            ['name' => 'SiriusXM Outlaw Country', 'stream_url' => 'https://n03.radiojar.com/8urd2zk0cg8uv.mp3', 'genre' => 'Country', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.siriusxm.com/outlawcountry', 'description' => 'Outlaw country music.', 'tags' => ['country', 'outlaw country', 'americana']],
            ['name' => 'The Wolf Country', 'stream_url' => 'https://streaming.live365.com/a57267', 'genre' => 'Country', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://thewolf.ca', 'description' => 'Today\'s best country.', 'tags' => ['country', 'new country']],

            // ---- Reggae / Caribbean -----------------------------------------
            ['name' => 'BBC 1Xtra', 'stream_url' => 'https://as-hls-ww-live.akamaized.net/pool_904/live/ww/bbc_1xtra/bbc_1xtra.isml/bbc_1xtra-audio%3d96000.norewind.m3u8', 'genre' => 'Reggae / Urban', 'country' => 'UK', 'language' => 'en', 'bitrate' => 96, 'format' => 'aac', 'homepage' => 'https://www.bbc.co.uk/1xtra', 'description' => 'UK\'s No.1 urban music station.', 'tags' => ['reggae', 'urban', 'grime', 'bbc']],
            ['name' => 'Reggae Radio Station', 'stream_url' => 'https://reggaeradio.cdnstream1.com/8016_128', 'genre' => 'Reggae', 'country' => 'UK', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://reggaeradiostation.co.uk', 'description' => '24/7 reggae.', 'tags' => ['reggae', 'dancehall', 'ska']],

            // ---- Oldies / 80s / 90s -----------------------------------------
            ['name' => 'Radio 80s80s', 'stream_url' => 'https://streams.80s80s.de/80s80s/mp3-128/streams', 'genre' => '80s', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.80s80s.de', 'description' => 'Die Besten aus den 80ern.', 'tags' => ['80s', 'hits', 'retro']],
            ['name' => 'Radio 90s90s', 'stream_url' => 'https://streams.90s90s.de/90s90s/mp3-128/streams', 'genre' => '90s', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.90s90s.de', 'description' => 'Die Hits der 90er.', 'tags' => ['90s', 'hits', 'retro']],
            ['name' => 'Absolute 80s', 'stream_url' => 'https://stream-mz.planetradio.co.uk/absolute80s.mp3', 'genre' => '80s', 'country' => 'UK', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.absoluteradio.co.uk/absolute-80s', 'description' => 'The best of the eighties.', 'tags' => ['80s', 'pop', 'retro']],
            ['name' => 'Magic Oldies FM', 'stream_url' => 'https://strm112.1.fm/oldies_mobile_mp3', 'genre' => 'Oldies', 'country' => 'USA', 'language' => 'en', 'bitrate' => 64, 'format' => 'mp3', 'homepage' => 'https://www.1.fm', 'description' => 'Classic hits from the 50s-70s.', 'tags' => ['oldies', 'classic hits', '60s', '70s']],

            // ---- Schlager / Volksmusik ------------------------------------
            ['name' => 'Schlager Radio', 'stream_url' => 'https://streams.schlagerradio.de/schlagerradio/mp3-128/streams', 'genre' => 'Schlager', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.schlager-radio.de', 'description' => 'Schlager non-stop.', 'tags' => ['schlager', 'volksmusik', 'deutsch']],
            ['name' => 'Volksmusik Radio', 'stream_url' => 'https://streams.volksmusikradio.com/volksmusikradio/mp3-128', 'genre' => 'Volksmusik', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.volksmusikradio.com', 'description' => 'Echte Volksmusik.', 'tags' => ['volksmusik', 'schlager', 'alpenrock']],

            // ---- World / Latin / Reggaeton ---------------------------------
            ['name' => 'Radio Globo Brasil', 'stream_url' => 'https://radios.brlogos.com/radiosglobo/mp3', 'genre' => 'Samba / Forró', 'country' => 'Brasilien', 'language' => 'pt', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.radioglobo.com.br', 'description' => 'O melhor da música brasileira.', 'tags' => ['samba', 'forro', 'axe', 'brasil']],
            ['name' => 'Maxima FM España', 'stream_url' => 'https://playerservices.streamtheworld.com/api/livestream-redirect/MAXIMAFM_AACP', 'genre' => 'Latin / Dance', 'country' => 'Spanien', 'language' => 'es', 'bitrate' => 64, 'format' => 'aac', 'homepage' => 'https://www.maxima.fm', 'description' => 'La más bailable.', 'tags' => ['latin', 'dance', 'reggaeton', 'espanol']],
            ['name' => 'Radio Monte Carlo', 'stream_url' => 'https://listen.radiomontecarloitalia.it/rmc', 'genre' => 'Pop / International', 'country' => 'Italien', 'language' => 'it', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.rmc.it', 'description' => 'Radio internazionale italiana.', 'tags' => ['pop', 'international', 'italiano']],

            // ---- News / Talk -----------------------------------------------
            ['name' => 'BBC World Service', 'stream_url' => 'https://stream.live.vc.bbcmedia.co.uk/bbc_world_service', 'genre' => 'News / Talk', 'country' => 'UK', 'language' => 'en', 'bitrate' => 48, 'format' => 'mp3', 'homepage' => 'https://www.bbc.co.uk/worldservice', 'description' => 'International news and programming.', 'tags' => ['news', 'world', 'bbc']],
            ['name' => 'Deutsche Welle', 'stream_url' => 'https://icecast.dw.com/dw/german/icecast.audio', 'genre' => 'News / Talk', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 64, 'format' => 'mp3', 'homepage' => 'https://www.dw.com', 'description' => 'Nachrichten auf Deutsch.', 'tags' => ['news', 'nachrichten', 'dw']],
            ['name' => 'NPR News (WAMU)', 'stream_url' => 'https://wamu-hls.streamguys1.com/wamu.m3u8', 'genre' => 'News / Public Radio', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'aac', 'homepage' => 'https://wamu.org', 'description' => 'Washington D.C. public radio.', 'tags' => ['news', 'npr', 'public radio']],

            // ---- Chillout / Ambient ----------------------------------------
            ['name' => 'SomaFM: Groove Salad', 'stream_url' => 'https://ice4.somafm.com/groovesalad-128-mp3', 'genre' => 'Chillout / Ambient', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://somafm.com/groovesalad', 'description' => 'A nicely chilled plate of ambient/downtempo beats.', 'tags' => ['chillout', 'ambient', 'downtempo']],
            ['name' => 'SomaFM: Drone Zone', 'stream_url' => 'https://ice4.somafm.com/dronezone-128-mp3', 'genre' => 'Ambient / Drone', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://somafm.com/dronezone', 'description' => 'Served best chilled, safe with most medications.', 'tags' => ['ambient', 'drone', 'space']],
            ['name' => 'Radio Paradise (Eclectic)', 'stream_url' => 'https://stream.radioparadise.com/eclectic-128', 'genre' => 'Eclectic', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.radioparadise.com', 'description' => 'Intelligent mix of rock, world, jazz, and more.', 'tags' => ['eclectic', 'world', 'rock', 'jazz']],
            ['name' => 'Radio Paradise (Rock)', 'stream_url' => 'https://stream.radioparadise.com/rock-128', 'genre' => 'Rock / Eclectic', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.radioparadise.com', 'description' => 'Rock channel from Radio Paradise.', 'tags' => ['rock', 'eclectic', 'indie']],

            // ---- Gaming / Anime / Geek --------------------------------------
            ['name' => 'SomaFM: Video Game Music', 'stream_url' => 'https://ice4.somafm.com/vgm-128-mp3', 'genre' => 'Video Game Music', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://somafm.com/vgm', 'description' => 'Video game music 24/7.', 'tags' => ['gaming', 'vgm', 'chiptune', 'ost']],
            ['name' => 'Rainwave (Chiptune)', 'stream_url' => 'https://stream.rainwave.cc/4/always.mp3?1', 'genre' => 'Chiptune', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://rainwave.cc', 'description' => 'Video game & anime radio.', 'tags' => ['chiptune', 'gaming', 'anime']],

            // ---- Christian / Gospel -----------------------------------------
            ['name' => 'KLOVE', 'stream_url' => 'https://maestro.emfbroadcasting.net/klave-web/klove', 'genre' => 'Christian / Gospel', 'country' => 'USA', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.klove.com', 'description' => 'Christian music for all ages.', 'tags' => ['christian', 'gospel', 'contemporary']],

            // ---- Spanish language ------------------------------------------
            ['name' => 'Los 40 Principales', 'stream_url' => 'https://playerservices.streamtheworld.com/api/livestream-redirect/LOS40_AACP', 'genre' => 'Pop / Hits', 'country' => 'Spanien', 'language' => 'es', 'bitrate' => 64, 'format' => 'aac', 'homepage' => 'https://www.los40.com', 'description' => 'La lista oficial de España.', 'tags' => ['pop', 'hits', 'español']],

            // ---- Russian ----------------------------------------------------
            ['name' => 'Европа Плюс', 'stream_url' => 'https://ep256.hostingradio.ru:8052/ep256.mp3', 'genre' => 'Pop', 'country' => 'Russland', 'language' => 'ru', 'bitrate' => 256, 'format' => 'mp3', 'homepage' => 'https://www.europaplus.ru', 'description' => 'Europe Plus Russia.', 'tags' => ['pop', 'russian', 'hits']],

            // ---- Japanese / Asian -------------------------------------------
            ['name' => 'J-Pop Sakura (internet)', 'stream_url' => 'https://igor.torontocast.com:1710/;', 'genre' => 'J-Pop', 'country' => 'Japan', 'language' => 'ja', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://jpopsuki.eu', 'description' => 'Japanese pop music.', 'tags' => ['j-pop', 'anime', 'japanese']],

            // ---- Schlager Austria -------------------------------------------
            ['name' => 'ORF Hitradio Ö3', 'stream_url' => 'https://oe3shoutcast.sf.apa.at/;', 'genre' => 'Pop / Hits', 'country' => 'Österreich', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://oe3.orf.at', 'description' => 'Österreichs meistgehörter Radiosender.', 'tags' => ['pop', 'hits', 'orf', 'östereich']],
            ['name' => 'SRF 3 Schweiz', 'stream_url' => 'https://stream.srg-ssr.ch/srf-3/mp3_128.m3u', 'genre' => 'Pop / Indie', 'country' => 'Schweiz', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.srf.ch/radio/srf-3', 'description' => 'Der Unterhaltungssender der Schweiz.', 'tags' => ['pop', 'indie', 'srf', 'schweiz']],

            // ---- Public Radio DE -------------------------------------------
            ['name' => 'Deutschlandfunk', 'stream_url' => 'https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3', 'genre' => 'News / Kultur', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.deutschlandfunk.de', 'description' => 'Nachrichten und Hintergrundberichte.', 'tags' => ['news', 'kultur', 'öffentlich-rechtlich']],
            ['name' => 'WDR 2', 'stream_url' => 'https://wdr-wdr2-rheinland.icecastssl.wdr.de/wdr/wdr2/rheinland/mp3/128/stream.mp3', 'genre' => 'Pop / Nachrichten', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www1.wdr.de/radio/wdr2', 'description' => 'NRW hört WDR 2.', 'tags' => ['pop', 'wdr', 'öffentlich-rechtlich']],
            ['name' => 'Bayern 3', 'stream_url' => 'https://dispatcher.rndfnk.com/br/br3/live/mp3/128/stream.mp3', 'genre' => 'Pop / Hits', 'country' => 'Deutschland', 'language' => 'de', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://www.br.de/radio/bayern3', 'description' => 'Das Popradio des BR.', 'tags' => ['pop', 'br', 'öffentlich-rechtlich', 'hits']],

            // ---- Lofi / Study -----------------------------------------------
            ['name' => 'Lofi Girl Radio', 'stream_url' => 'https://play.streamafrica.net/lofiradio', 'genre' => 'Lofi / Study', 'country' => 'Frankreich', 'language' => 'en', 'bitrate' => 128, 'format' => 'mp3', 'homepage' => 'https://lofigirl.com', 'description' => 'Lofi hip hop beats to study/relax to.', 'tags' => ['lofi', 'hip hop', 'study', 'chill']],
        ];
    }
}
