<?php
/**
 * Plantilla en blanco para /quiz/{slug}/ (sin header, footer ni estilos del tema).
 *
 * @var array $quiz
 * @var array $payload
 * @var array $assets
 */

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html lang="<?php echo esc_attr( substr( get_locale(), 0, 2 ) ); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php $seo = MSC_Quiz_Public::seo( $quiz ); ?>
  <title><?php echo esc_html( $seo['title'] ); ?></title>
  <meta name="description" content="<?php echo esc_attr( $seo['description'] ); ?>">
  <meta name="robots" content="<?php echo $seo['index'] ? 'index, follow, max-image-preview:large' : 'noindex, follow'; ?>">
  <link rel="canonical" href="<?php echo esc_url( $seo['url'] ); ?>">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="<?php echo esc_attr( $seo['locale'] ); ?>">
  <meta property="og:site_name" content="<?php echo esc_attr( $seo['site_name'] ); ?>">
  <meta property="og:title" content="<?php echo esc_attr( $seo['og_title'] ); ?>">
  <meta property="og:description" content="<?php echo esc_attr( $seo['description'] ); ?>">
  <meta property="og:url" content="<?php echo esc_url( $seo['url'] ); ?>">
  <?php if ( $seo['modified'] ) : ?>
  <meta property="og:updated_time" content="<?php echo esc_attr( $seo['modified'] ); ?>">
  <?php endif; ?>
  <?php if ( $seo['image'] ) : ?>
  <meta property="og:image" content="<?php echo esc_url( $seo['image']['url'] ); ?>">
    <?php if ( $seo['image']['width'] && $seo['image']['height'] ) : ?>
  <meta property="og:image:width" content="<?php echo (int) $seo['image']['width']; ?>">
  <meta property="og:image:height" content="<?php echo (int) $seo['image']['height']; ?>">
    <?php endif; ?>
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:image" content="<?php echo esc_url( $seo['image']['url'] ); ?>">
  <?php else : ?>
  <meta name="twitter:card" content="summary">
  <?php endif; ?>
  <meta name="twitter:title" content="<?php echo esc_attr( $seo['og_title'] ); ?>">
  <meta name="twitter:description" content="<?php echo esc_attr( $seo['description'] ); ?>">
  <script type="application/ld+json"><?php
	echo wp_json_encode(
		array_filter( array(
			'@context'    => 'https://schema.org',
			'@type'       => 'WebPage',
			'name'        => $seo['og_title'],
			'description' => $seo['description'],
			'url'         => $seo['url'],
			'inLanguage'  => str_replace( '_', '-', $seo['locale'] ),
			'image'       => $seo['image'] ? $seo['image']['url'] : null,
			'isPartOf'    => array(
				'@type' => 'WebSite',
				'name'  => $seo['site_name'],
				'url'   => home_url( '/' ),
			),
		) ),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
	);
	?></script>
  <?php wp_site_icon(); ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="<?php echo esc_url( MSC_Quiz_Public::font_url() ); ?>">
  <link rel="stylesheet" href="<?php echo esc_url( $assets['css'] ); ?>">
</head>
<body class="msc-quiz-body">
  <?php echo MSC_Quiz_Public::mount_markup( $payload, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
  <script>window.MSC_QUIZ = <?php echo wp_json_encode( $assets['config'] ); ?>;</script>
  <script src="<?php echo esc_url( $assets['js'] ); ?>"></script>
</body>
</html>
