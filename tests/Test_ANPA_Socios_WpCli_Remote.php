<?php
/**
 * Contract tests for the canonical IONOS WP-CLI wrapper.
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_WpCli_Remote extends TestCase {

	/**
	 * @var string
	 */
	private $script;

	/**
	 * Resolve the monorepo root directory.
	 *
	 * Priority:
	 * 1. ANPA_MONOREPO_ROOT environment variable (explicit, portable).
	 * 2. Git worktree detection (if plugin is inside a monorepo worktree).
	 * 3. dirname(__DIR__, 3) fallback (assumes plugin at <root>/plugins/anpa-socios/).
	 *
	 * @return string
	 */
	private static function resolve_monorepo_root(): string {
		$env = getenv( 'ANPA_MONOREPO_ROOT' );
		if ( $env !== false && $env !== '' ) {
			return rtrim( $env, '/' );
		}

		// Try git worktree detection: if the plugin is inside a monorepo,
		// the git root will be the monorepo root.
		$git_root = self::detect_git_root();
		if ( $git_root !== null ) {
			return $git_root;
		}

		return dirname( __DIR__, 3 );
	}

	/**
	 * Detect the git repository root from the plugin directory.
	 *
	 * @return string|null The git root path, or null if not found.
	 */
	private static function detect_git_root(): ?string {
		$dir = __DIR__;
		for ( $i = 0; $i < 6; $i++ ) {
			$candidate = dirname( $dir, $i );
			if ( $candidate === '/' || $candidate === '' ) {
				break;
			}
			if ( is_dir( $candidate . '/.git' ) ) {
				return $candidate;
			}
		}
		return null;
	}

	public function setUp(): void {
		parent::setUp();
		$root = self::resolve_monorepo_root();
		$this->script = $root . '/scripts/WpCli-Remote.ps1';
		if ( ! file_exists( $this->script ) ) {
			$this->markTestSkipped(
				'WpCli-Remote.ps1 not found at ' . $this->script .
				'. This is a monorepo contract test; set ANPA_MONOREPO_ROOT or run from the monorepo.'
			);
		}
	}

	public function test_monorepo_root_resolution_is_portable(): void {
		$root = self::resolve_monorepo_root();
		$this->assertIsString( $root );
		$this->assertNotEmpty( $root );
		$this->assertDirectoryExists( $root );
		$expected = $root . '/scripts/WpCli-Remote.ps1';
		if ( ! file_exists( $expected ) ) {
			$this->markTestSkipped(
				'WpCli-Remote.ps1 not found at ' . $expected .
				'. This is a monorepo contract test; set ANPA_MONOREPO_ROOT or run from the monorepo.'
			);
		}
		$this->assertFileExists( $expected, "WpCli-Remote.ps1 not found at: $expected" );
	}

	public function test_timeout_is_enforced_on_the_real_plink_process(): void {
		$source = (string) file_get_contents( $this->script );

		$this->assertStringContainsString( '[System.Diagnostics.ProcessStartInfo]::new()', $source );
		$this->assertStringContainsString( '$startInfo.ArgumentList.Add(', $source );
		$this->assertStringContainsString( '$process.WaitForExit($TimeoutSec * 1000)', $source );
		$this->assertStringContainsString( '$process.Kill()', $source );
		$this->assertStringContainsString( '$process.WaitForExit(1000)', $source );
		$this->assertStringContainsString( 'ReadToEndAsync()', $source );
		$this->assertStringNotContainsString( '& $plink.Source @plinkArgs', $source );
	}

	public function test_persistent_log_never_stores_the_full_command_or_output(): void {
		$source = (string) file_get_contents( $this->script );

		$this->assertStringContainsString( '$safeCommand', $source );
		$this->assertStringContainsString( 'output_redacted=true', $source );
		$this->assertStringNotContainsString( '-- wp $WpCommand (path=', $source );
		$this->assertStringNotContainsString( 'Add-Content -LiteralPath $logFile -Value "  | $truncated"', $source );
	}

	public function test_remote_and_timeout_exit_codes_are_propagated(): void {
		$source = (string) file_get_contents( $this->script );

		$this->assertStringContainsString( 'exit 124', $source );
		$this->assertStringContainsString( 'exit $exitCode', $source );
		$this->assertStringNotContainsString( 'throw "wp-cli devolveu exit code $exitCode', $source );
	}

	public function test_remote_command_and_password_are_not_exposed_to_shell_or_process_list(): void {
		$source = (string) file_get_contents( $this->script );

		$this->assertStringContainsString( 'function ConvertTo-ANPAWpCliArguments', $source );
		$this->assertStringContainsString( 'function ConvertTo-ANPAPosixLiteral', $source );
		$this->assertStringContainsString( '$passwordFile', $source );
		$this->assertStringContainsString( "'-pwfile', \$passwordFile", $source );
		$this->assertStringContainsString( 'Remove-Item -LiteralPath $passwordFile', $source );
		$this->assertStringNotContainsString( "'-pw', \$password", $source );
		$this->assertStringNotContainsString( '/migrationwp', $source );
	}
}
