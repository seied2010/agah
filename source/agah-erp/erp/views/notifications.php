<?php
/** مرکز اعلان داخلی (NTF-02): خوانده‌نشده/خوانده، پیوند به مورد. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Jalali,Money};
use Agah\Erp\Modules\Notify\NotificationService as N;
if(!defined('ABSPATH'))exit;
$rows=N::mine($cid);
?>
<section class="erp-card"><h2>مرکز اعلان‌ها</h2>
<?php if(N::unread($cid)): ?><form method="post" class="erp-inline-form"><?php self::form('module_notify_read',$cid,'notifications'); ?><button type="submit" class="btn btn-outline btn-sm">همه خوانده شد</button></form><?php endif; ?>
<table class="erp-table"><thead><tr><th>نوع</th><th>اعلان</th><th>زمان</th><th>وضعیت</th></tr></thead><tbody>
<?php foreach($rows as $n): ?><tr<?php echo $n['read_at']?'':' class="erp-unread"'; ?>><td><?php echo esc_html(N::TYPES[$n['type']]??$n['type']); ?></td><td><?php if($n['link']): ?><a href="<?php echo esc_url($n['link']); ?>"><?php echo esc_html($n['title']); ?></a><?php else: echo esc_html($n['title']); endif; ?><?php if($n['body']!==''): ?><br><small><?php echo esc_html($n['body']); ?></small><?php endif; ?></td><td><?php echo esc_html(Jalali::format(substr(get_date_from_gmt($n['created_at']),0,10)).' '.Money::toFa(substr(get_date_from_gmt($n['created_at']),11,5))); ?></td><td><?php if($n['read_at']): ?>خوانده‌شده<?php else: ?><form method="post" class="erp-inline-form"><?php self::form('module_notify_read',$cid,'notifications',$n['id']); ?><button type="submit" class="btn btn-outline btn-sm">خواندم</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></section>
