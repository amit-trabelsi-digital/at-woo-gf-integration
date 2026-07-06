<?php
/**
 * Cookie Consent — admin settings page.
 *
 * @var array $settings  Current settings.
 * @var array $detected  Uncategorized cookies observed by the scanner.
 * @package AT_Woo_GF_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$categories = $settings['categories'];
$cookies    = $settings['cookies'];
?>
<div class="wrap at-cc-admin" dir="rtl">
	<h1><?php esc_html_e( 'הסכמת עוגיות (GDPR)', 'at-woo-gf-integration' ); ?></h1>

	<?php if ( ! empty( $detected ) ) : ?>
		<div class="notice notice-warning at-cc-admin__detected">
			<p><strong><?php esc_html_e( 'עוגיות שזוהו וטרם סווגו:', 'at-woo-gf-integration' ); ?></strong></p>
			<p class="at-cc-admin__detected-list">
				<?php foreach ( $detected as $name => $ts ) : ?>
					<button type="button" class="button button-small at-cc-admin__add-detected" data-name="<?php echo esc_attr( $name ); ?>">
						<?php echo esc_html( $name ); ?> +
					</button>
				<?php endforeach; ?>
			</p>
			<p class="description"><?php esc_html_e( 'לחיצה על עוגייה תוסיף אותה לרשימה למטה לסיווג. לאחר שמירה היא תיעלם מכאן.', 'at-woo-gf-integration' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="options.php" class="at-cc-admin__form">
		<?php settings_fields( 'at_woo_gf_cookie_group' ); ?>

		<h2><?php esc_html_e( 'כללי', 'at-woo-gf-integration' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'הפעלת באנר', 'at-woo-gf-integration' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( AT_Woo_GF_Cookie_Consent::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
						<?php esc_html_e( 'הצג את באנר הסכמת העוגיות באתר', 'at-woo-gf-integration' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'מיקום', 'at-woo-gf-integration' ); ?></th>
				<td>
					<select name="<?php echo esc_attr( AT_Woo_GF_Cookie_Consent::OPTION ); ?>[position]">
						<option value="bottom" <?php selected( $settings['position'], 'bottom' ); ?>><?php esc_html_e( 'תחתית העמוד', 'at-woo-gf-integration' ); ?></option>
						<option value="top" <?php selected( $settings['position'], 'top' ); ?>><?php esc_html_e( 'ראש העמוד', 'at-woo-gf-integration' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'טקסט הבאנר', 'at-woo-gf-integration' ); ?></th>
				<td>
					<textarea name="<?php echo esc_attr( AT_Woo_GF_Cookie_Consent::OPTION ); ?>[banner_text]" rows="3" class="large-text"><?php echo esc_textarea( $settings['banner_text'] ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'קישור למדיניות פרטיות', 'at-woo-gf-integration' ); ?></th>
				<td>
					<input type="url" name="<?php echo esc_attr( AT_Woo_GF_Cookie_Consent::OPTION ); ?>[privacy_url]" value="<?php echo esc_attr( $settings['privacy_url'] ); ?>" class="regular-text" placeholder="https://">
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'קטגוריות', 'at-woo-gf-integration' ); ?></h2>
		<table class="widefat striped at-cc-admin__cats">
			<thead>
				<tr>
					<th><?php esc_html_e( 'קטגוריה', 'at-woo-gf-integration' ); ?></th>
					<th><?php esc_html_e( 'שם תצוגה', 'at-woo-gf-integration' ); ?></th>
					<th><?php esc_html_e( 'תיאור', 'at-woo-gf-integration' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $categories as $key => $cat ) : ?>
					<tr>
						<td><code><?php echo esc_html( $key ); ?></code><?php echo ! empty( $cat['locked'] ) ? ' <span class="description">(' . esc_html__( 'נעולה', 'at-woo-gf-integration' ) . ')</span>' : ''; ?></td>
						<td><input type="text" name="<?php echo esc_attr( AT_Woo_GF_Cookie_Consent::OPTION ); ?>[categories][<?php echo esc_attr( $key ); ?>][label]" value="<?php echo esc_attr( $cat['label'] ); ?>" class="regular-text"></td>
						<td><textarea name="<?php echo esc_attr( AT_Woo_GF_Cookie_Consent::OPTION ); ?>[categories][<?php echo esc_attr( $key ); ?>][description]" rows="2" class="large-text"><?php echo esc_textarea( $cat['description'] ); ?></textarea></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'רשימת עוגיות', 'at-woo-gf-integration' ); ?></h2>
		<p class="description"><?php esc_html_e( 'הגדר את העוגיות שהאתר משתמש בהן ואת סיווגן. עוגיות שלא ברשימה יסומנו כ"טרם סווגו".', 'at-woo-gf-integration' ); ?></p>
		<table class="widefat striped at-cc-admin__cookies" id="at-cc-cookies">
			<thead>
				<tr>
					<th><?php esc_html_e( 'שם', 'at-woo-gf-integration' ); ?></th>
					<th><?php esc_html_e( 'קטגוריה', 'at-woo-gf-integration' ); ?></th>
					<th><?php esc_html_e( 'ספק', 'at-woo-gf-integration' ); ?></th>
					<th><?php esc_html_e( 'מטרה', 'at-woo-gf-integration' ); ?></th>
					<th><?php esc_html_e( 'תפוגה', 'at-woo-gf-integration' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody data-at-cc-rows>
				<?php
				$opt = AT_Woo_GF_Cookie_Consent::OPTION;
				foreach ( $cookies as $i => $c ) :
					?>
					<tr class="at-cc-admin__row">
						<td><input type="text" name="<?php echo esc_attr( $opt ); ?>[cookies][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $c['name'] ); ?>"></td>
						<td>
							<select name="<?php echo esc_attr( $opt ); ?>[cookies][<?php echo (int) $i; ?>][category]">
								<?php foreach ( $categories as $ckey => $cat ) : ?>
									<option value="<?php echo esc_attr( $ckey ); ?>" <?php selected( $c['category'], $ckey ); ?>><?php echo esc_html( $cat['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><input type="text" name="<?php echo esc_attr( $opt ); ?>[cookies][<?php echo (int) $i; ?>][provider]" value="<?php echo esc_attr( $c['provider'] ); ?>"></td>
						<td><input type="text" name="<?php echo esc_attr( $opt ); ?>[cookies][<?php echo (int) $i; ?>][purpose]" value="<?php echo esc_attr( $c['purpose'] ); ?>"></td>
						<td><input type="text" name="<?php echo esc_attr( $opt ); ?>[cookies][<?php echo (int) $i; ?>][expiry]" value="<?php echo esc_attr( $c['expiry'] ); ?>"></td>
						<td><button type="button" class="button-link-delete at-cc-admin__remove"><?php esc_html_e( 'הסר', 'at-woo-gf-integration' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<button type="button" class="button" id="at-cc-add-cookie"><?php esc_html_e( 'הוסף עוגייה', 'at-woo-gf-integration' ); ?></button>
		</p>

		<?php submit_button( __( 'שמירת הגדרות', 'at-woo-gf-integration' ) ); ?>
	</form>

	<!-- Row template for JS -->
	<template id="at-cc-row-template">
		<tr class="at-cc-admin__row">
			<td><input type="text" data-field="name" value=""></td>
			<td>
				<select data-field="category">
					<?php foreach ( $categories as $ckey => $cat ) : ?>
						<option value="<?php echo esc_attr( $ckey ); ?>"><?php echo esc_html( $cat['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" data-field="provider" value=""></td>
			<td><input type="text" data-field="purpose" value=""></td>
			<td><input type="text" data-field="expiry" value=""></td>
			<td><button type="button" class="button-link-delete at-cc-admin__remove"><?php esc_html_e( 'הסר', 'at-woo-gf-integration' ); ?></button></td>
		</tr>
	</template>
</div>
