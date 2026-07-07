<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameConfig;
use Tests\IntegrationTestCase;

final class GameConfigTest extends IntegrationTestCase
{
    public function testReadsSeededDefaults(): void
    {
        $this->pdo->exec("INSERT INTO game_config (config_key, config_value) VALUES
            ('pioneer_p0','100'),('pioneer_k','12'),('pioneer_s','4'),
            ('hysteresis_factor','1.15'),('presence_window_days','90'),
            ('popularity_c','30'),('value_combine','max'),
            ('auth_min_speed_kmh','5'),('auth_max_hacc_m','30'),
            ('auth_require_motion','1'),('start_buffer_m','0'),
            ('curation_per_hint','5'),('curation_per_like','2'),
            ('presence_decay','linear')");

        $cfg = new GameConfig($this->pdo);
        $this->assertSame(100.0, $cfg->float('pioneer_p0'));
        $this->assertSame(12.0, $cfg->float('pioneer_k'));
        $this->assertSame(1.15, $cfg->float('hysteresis_factor'));
        $this->assertSame(90, $cfg->int('presence_window_days'));
        $this->assertTrue($cfg->bool('auth_require_motion'));
        $this->assertSame('max', $cfg->string('value_combine'));
    }

    public function testFallsBackToDefaultWhenKeyMissing(): void
    {
        $cfg = new GameConfig($this->pdo); // game_config leer nach TRUNCATE
        $this->assertSame(100.0, $cfg->float('pioneer_p0'));
        $this->assertSame(1.15, $cfg->float('hysteresis_factor'));
    }

    public function testResolvesMapEdgeCapsPerDeviceClass(): void
    {
        $cfg = new GameConfig($this->pdo); // greift auf eingebaute Defaults zurück

        // Bekannte Generation → deren Kappe; Fetch-Limit = round(cap × 1.25).
        $caps = $cfg->resolveMapEdgeCaps('iPhone 15', 'iPhone16,1');
        $this->assertSame(3000, $caps['max_render_edges']);
        $this->assertSame(3750, $caps['edge_request_limit']);

        // Unbekannte Generation → default.
        $this->assertSame(2000, $cfg->resolveMapEdgeCaps('iPhone 99', 'iPhoneX,Y')['max_render_edges']);

        // Ohne device_class: rohe Kennung greift, sonst default.
        $this->assertSame(2000, $cfg->resolveMapEdgeCaps(null, null)['max_render_edges']);
    }

    public function testResolvesMapEdgeCapsFromDbOverride(): void
    {
        $this->pdo->exec("INSERT INTO game_config (config_key, config_value) VALUES
            ('map_edge_render_caps', '{\"default\":1000,\"iPhone 12\":1800}'),
            ('map_edge_fetch_multiplier', '1.5')");

        $cfg = new GameConfig($this->pdo);
        $caps = $cfg->resolveMapEdgeCaps('iPhone 12', 'iPhone13,2');
        $this->assertSame(1800, $caps['max_render_edges']);
        $this->assertSame(2700, $caps['edge_request_limit']); // 1800 × 1.5
        $this->assertSame(1000, $cfg->resolveMapEdgeCaps('iPhone 17', null)['max_render_edges']);
    }
}
