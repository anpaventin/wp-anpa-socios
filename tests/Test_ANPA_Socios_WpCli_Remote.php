<?php
/**
 * Contract tests for the canonical IONOS WP-CLI wrapper.
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_ANPA_Socios_WpCli_Remote extends TestCase {

	/** @var string */
	private $script;

	public function setUp(): void {
		parent::setUp();
		$this->script = dirname( __DIR__, 3 ) . '/scripts/WpCli-Remote.ps1';
		$this->assertFileExists( $this->script );
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
