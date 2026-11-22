<?php
/** پوستهٔ مستقل صفحهٔ سامانه؛ به هیچ فایل قالبی وابسته نیست. */
if ( ! defined( 'ABSPATH' ) ) exit;
remove_action( 'wp_head', '_wp_render_title_tag', 1 );
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( get_option( 'agah_erp_title', 'سامانه حسابداری' ) ?: 'سامانه حسابداری' ); ?></title>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'agah-erp-shell' ); ?>>
<?php wp_body_open(); ?>
<a class="erp-skip-link" href="#<?php echo \Agah\Erp\Core\Ctx::cidOrZero()?'agah-work-content':'content'; ?>">رفتن به محتوای اصلی</a>
<main id="content">
<?php while ( have_posts() ) : the_post(); the_content(); endwhile; ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
