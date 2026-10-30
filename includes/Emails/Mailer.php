<?php
/**
 * Email renderer and sender.
 *
 * Emails are rendered from templates/emails/{template}.php (overridable in
 * {theme}/wahou-commerce/emails/) inside a responsive, table-based layout with
 * inline styles, then sent as HTML with a plain-text alternative.
 *
 * Every email is rendered in the recipient's language: wrap the subject and
 * the rendering in Mailer::with_locale( $locale, fn() => ... ).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Emails;

use WahouCommerce\Settings;
use WahouCommerce\Support\Logger;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

final class Mailer {

	private static string $alt_body = '';

	/**
	 * Run a callback in another locale (falls back to the current one when the
	 * locale is not available on the site).
	 *
	 * @return mixed Callback result.
	 */
	public static function with_locale( ?string $locale, callable $callback ) {
		$switched = false;
		if ( $locale && function_exists( 'switch_to_locale' ) && $locale !== determine_locale() ) {
			$switched = switch_to_locale( $locale );
		}
		try {
			return $callback();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Render and send an email.
	 *
	 * @param string|string[]      $to       Recipient(s).
	 * @param string               $subject  Subject (already translated).
	 * @param string               $template Template name inside templates/emails/.
	 * @param array<string, mixed> $vars     Template variables. Recognized: heading, preheader, unsubscribe_url.
	 * @param array<string, mixed> $opts     reply_to (string), attachments (string[]), headers (string[]).
	 */
	public static function send( $to, string $subject, string $template, array $vars = array(), array $opts = array() ): bool {
		$recipients = array_filter( array_map( 'sanitize_email', (array) $to ), 'is_email' );
		if ( empty( $recipients ) ) {
			return false;
		}

		$html = self::render( $template, array_merge( $vars, array( 'subject' => $subject ) ) );
		if ( '' === trim( $html ) ) {
			return false;
		}

		$headers   = array( 'Content-Type: text/html; charset=UTF-8' );
		$from      = self::from_address();
		$from_name = self::from_name();
		if ( $from ) {
			$headers[] = sprintf( 'From: %s <%s>', self::encode_header( $from_name ), $from );
		}
		if ( ! empty( $opts['reply_to'] ) && is_email( $opts['reply_to'] ) ) {
			$headers[] = 'Reply-To: ' . sanitize_email( (string) $opts['reply_to'] );
		}
		if ( ! empty( $vars['unsubscribe_url'] ) ) {
			$headers[] = 'List-Unsubscribe: <' . esc_url_raw( (string) $vars['unsubscribe_url'] ) . '>';
		}
		foreach ( (array) ( $opts['headers'] ?? array() ) as $header ) {
			$headers[] = (string) $header;
		}

		/**
		 * Filters an email before it is sent.
		 *
		 * @param array<string, mixed> $email to, subject, html, headers, template, vars.
		 */
		$email = apply_filters(
			'wahou_email',
			array(
				'to'       => $recipients,
				'subject'  => wp_specialchars_decode( $subject, ENT_QUOTES ),
				'html'     => $html,
				'headers'  => $headers,
				'template' => $template,
				'vars'     => $vars,
			)
		);
		if ( empty( $email ) || empty( $email['to'] ) ) {
			return false;
		}

		self::$alt_body = self::to_text( (string) $email['html'] );
		add_action( 'phpmailer_init', array( self::class, 'set_alt_body' ) );
		$sent = wp_mail( $email['to'], $email['subject'], $email['html'], $email['headers'], (array) ( $opts['attachments'] ?? array() ) );
		remove_action( 'phpmailer_init', array( self::class, 'set_alt_body' ) );
		self::$alt_body = '';

		if ( ! $sent ) {
			Logger::error( 'emails', 'Email could not be sent', array( 'template' => $template ) );
		}
		return (bool) $sent;
	}

	/**
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public static function set_alt_body( $phpmailer ): void {
		if ( '' !== self::$alt_body && is_object( $phpmailer ) ) {
			$phpmailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/**
	 * Render a template inside the layout.
	 *
	 * @param array<string, mixed> $vars
	 */
	public static function render( string $template, array $vars = array() ): string {
		$content = Template::get( 'emails/' . $template, $vars );
		if ( '' === trim( $content ) ) {
			return '';
		}
		$logo_id = Settings::int( 'email_logo' );
		$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

		return Template::get(
			'emails/layout',
			array(
				'content'         => $content,
				'subject'         => (string) ( $vars['subject'] ?? '' ),
				'heading'         => (string) ( $vars['heading'] ?? '' ),
				'preheader'       => (string) ( $vars['preheader'] ?? '' ),
				'unsubscribe_url' => (string) ( $vars['unsubscribe_url'] ?? '' ),
				'logo'            => $logo ? (string) $logo : '',
				'store_name'      => Settings::store_name(),
				'store_url'       => home_url( '/' ),
				'footer'          => self::footer_html(),
				'brand'           => self::brand_color(),
				'on_brand'        => self::on_brand_color(),
				'lang'            => str_replace( '_', '-', determine_locale() ),
				'dir'             => is_rtl() ? 'rtl' : 'ltr',
			)
		);
	}

	public static function from_address(): string {
		$address = Settings::str( 'email_from_address' );
		if ( ! is_email( $address ) ) {
			$address = Settings::store_email();
		}
		return is_email( $address ) ? $address : '';
	}

	public static function from_name(): string {
		$name = Settings::str( 'email_from_name' );
		return '' !== $name ? $name : Settings::store_name();
	}

	public static function brand_color(): string {
		$color = sanitize_hex_color( Settings::str( 'brand_color' ) );
		return $color ? $color : '#5b3df5';
	}

	/**
	 * Readable text color on the brand color (WCAG relative luminance).
	 */
	public static function on_brand_color(): string {
		$hex = ltrim( self::brand_color(), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$channels = array_map(
			static function ( string $c ): float {
				$v = hexdec( $c ) / 255;
				return $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
			},
			str_split( substr( $hex, 0, 6 ), 2 )
		);
		$luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
		return $luminance > 0.42 ? '#111111' : '#ffffff';
	}

	/**
	 * Footer: store identity (legal requirement for commercial emails) + custom text.
	 */
	public static function footer_html(): string {
		$parts = array();
		$name  = Settings::str( 'store_legal_name' );
		$name  = '' !== $name ? $name : Settings::store_name();
		$parts[] = '<strong>' . esc_html( $name ) . '</strong>';
		$address = trim( Settings::str( 'store_address' ) );
		if ( '' !== $address ) {
			$parts[] = nl2br( esc_html( $address ) );
		}
		$custom = trim( Settings::str( 'email_footer' ) );
		if ( '' !== $custom ) {
			$parts[] = wp_kses_post( nl2br( $custom ) );
		}
		return implode( '<br>', $parts );
	}

	/**
	 * Inline style presets shared by all templates (email clients ignore <style> often).
	 */
	public static function style( string $element ): string {
		$font  = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
		$brand = self::brand_color();
		$map   = array(
			'p'      => $font . 'margin:0 0 16px;font-size:16px;line-height:1.55;color:#1f2328;',
			'h1'     => $font . 'margin:0 0 16px;font-size:24px;line-height:1.25;font-weight:700;color:#111111;',
			'h2'     => $font . 'margin:28px 0 12px;font-size:18px;line-height:1.3;font-weight:700;color:#111111;',
			'small'  => $font . 'margin:0 0 12px;font-size:13px;line-height:1.5;color:#6a737d;',
			'muted'  => $font . 'font-size:14px;line-height:1.5;color:#6a737d;',
			'table'  => 'width:100%;border-collapse:collapse;',
			'th'     => $font . 'text-align:left;padding:8px 0;font-size:13px;color:#6a737d;font-weight:600;border-bottom:1px solid #e6e8eb;',
			'td'     => $font . 'padding:10px 0;font-size:15px;line-height:1.45;color:#1f2328;border-bottom:1px solid #f0f1f3;vertical-align:top;',
			'td_r'   => $font . 'padding:10px 0;font-size:15px;line-height:1.45;color:#1f2328;border-bottom:1px solid #f0f1f3;vertical-align:top;text-align:right;white-space:nowrap;',
			'total'  => $font . 'padding:12px 0 0;font-size:18px;font-weight:700;color:#111111;',
			'total_r' => $font . 'padding:12px 0 0;font-size:18px;font-weight:700;color:#111111;text-align:right;white-space:nowrap;',
			'box'    => $font . 'background:#f6f7f9;border-radius:10px;padding:16px 18px;margin:0 0 20px;font-size:15px;line-height:1.5;color:#1f2328;',
			'link'   => 'color:' . $brand . ';text-decoration:underline;',
			'code'   => "font-family:SFMono-Regular,Consolas,'Liberation Mono',Menlo,monospace;font-size:20px;font-weight:700;letter-spacing:2px;background:#f6f7f9;border:1px dashed " . $brand . ';border-radius:8px;padding:10px 16px;display:inline-block;color:#111111;',
		);
		return $map[ $element ] ?? '';
	}

	/**
	 * Bulletproof call-to-action button.
	 */
	public static function button( string $url, string $label ): string {
		$brand    = self::brand_color();
		$on_brand = self::on_brand_color();
		return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;"><tr><td style="border-radius:10px;background:' . esc_attr( $brand ) . ';">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 26px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:16px;font-weight:700;line-height:1.2;color:' . esc_attr( $on_brand ) . ';text-decoration:none;border-radius:10px;">'
			. esc_html( $label ) . '</a></td></tr></table>';
	}

	/**
	 * Plain-text version of an HTML email.
	 */
	public static function to_text( string $html ): string {
		$html = preg_replace( '#<(head|style|script)[^>]*>.*?</\1>#si', '', $html ) ?? $html;
		$html = preg_replace( '#<div[^>]*display:\s*none[^>]*>.*?</div>#si', '', $html ) ?? $html;
		$html = preg_replace_callback(
			'#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#si',
			static function ( array $m ): string {
				$text = trim( wp_strip_all_tags( $m[3] ) );
				$url  = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
				return '' === $text || $text === $url ? $url : $text . ' (' . $url . ')';
			},
			$html
		) ?? $html;
		$html = preg_replace( '#<(br|/p|/h[1-6]|/tr|/li|/table|/div)[^>]*>#i', "\n", $html ) ?? $html;
		$html = preg_replace( '#</t[dh]>#i', "\t", $html ) ?? $html;
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text ) ?? $text;
		$text = preg_replace( "/ *\n */", "\n", $text ) ?? $text;
		$text = preg_replace( "/\n{3,}/", "\n\n", $text ) ?? $text;
		return trim( $text );
	}

	/**
	 * Header-safe display name (PHPMailer takes care of RFC 2047 encoding).
	 */
	private static function encode_header( string $value ): string {
		return trim( str_replace( array( "\r", "\n", '"', '<', '>', ',' ), ' ', $value ) );
	}
}
