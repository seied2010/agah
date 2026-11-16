<?php
/** نمای جداشدهٔ pageAccounts؛ زمینه و مجوز در Page بررسی می‌شوند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
		$rows    = AccountService::all( $cid );
		$canEdit = Ctx::can( 'account.edit' );
		$canDel  = Ctx::can( 'account.delete' );
		global $wpdb;
		$pg=max(1,Request::int('get','pg',1));$like='%'.$wpdb->esc_like(Request::str('get','q',100)).'%';
		$count=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('accounts').' WHERE company_id=%d AND (code LIKE %s OR name LIKE %s)',$cid,$like,$like);
		$display=Db::rows('SELECT * FROM '.Db::t('accounts').' WHERE company_id=%d AND (code LIKE %s OR name LIKE %s) ORDER BY code LIMIT 50 OFFSET %d',$cid,$like,$like,($pg-1)*50);
		ModuleController::search($cid,'accounts');

		echo '<div class="erp-card"><h2>کدینگ حساب‌ها</h2><div class="erp-scroll"><table class="erp-table"><thead><tr><th>کد</th><th>نام</th><th>طبقه</th><th>تفصیلی</th><th>وضعیت</th><th></th></tr></thead><tbody>';
		$cls = ChartSeed::classes();
		$dr  = array( 'none' => '—', 'optional' => 'اختیاری', 'required' => 'الزامی' );
		foreach ( $display as $a ) {
			$ind = str_repeat( '— ', (int) $a['level'] - 1 );
			echo '<tr class="lv' . (int) $a['level'] . '"><td dir="ltr">' . esc_html( self::fa( $a['code'] ) ) . '</td><td>' . esc_html( $ind . $a['name'] ) . '</td><td>' . esc_html( isset( $cls[ $a['stmt_class'] ] ) ? $cls[ $a['stmt_class'] ][0] : '' ) . '</td><td>' . esc_html( $dr[ $a['detail_rule'] ] ) . '</td><td>' . esc_html( (int) $a['is_active'] ? 'فعال' : 'غیرفعال' ) . '</td><td>';
			if($canEdit) echo '<a class="btn btn-outline btn-sm" href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>'accounts','edit'=>$a['id']])).'">ویرایش</a> ';
			if ( $canEdit && ! $a['sys_key'] ) {
				echo '<form method="post" class="inline">' . self::nonce( 'account_toggle' ) . '<input type="hidden" name="c" value="' . (int) $cid . '"><input type="hidden" name="back" value="accounts"><input type="hidden" name="id" value="' . (int) $a['id'] . '"><input type="hidden" name="active" value="' . ( (int) $a['is_active'] ? '0' : '1' ) . '"><button class="btn btn-outline btn-sm" type="submit">' . ( (int) $a['is_active'] ? 'غیرفعال' : 'فعال' ) . '</button></form> ';
			}
			if ( $canDel && ! $a['is_system'] ) {
				echo '<form method="post" class="inline">' . self::nonce( 'account_delete' ) . '<input type="hidden" name="c" value="' . (int) $cid . '"><input type="hidden" name="back" value="accounts"><input type="hidden" name="id" value="' . (int) $a['id'] . '"><button class="btn btn-outline btn-sm" type="submit">حذف</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div></div>';
		ModuleController::pages((int)ceil($count/50),$pg);
		if ( Ctx::can( 'account.create' ) ) {
			echo '<div class="erp-card"><h3>افزودن حساب</h3><form method="post" class="erp-form">' . self::nonce( 'account_create' ) . '<input type="hidden" name="c" value="' . (int) $cid . '"><input type="hidden" name="back" value="accounts">';
			echo '<label>حساب بالادست (گروه یا کل)<select name="parent_id">';
			foreach ( $rows as $a ) {
				if ( (int) $a['level'] < 3 ) {
					echo '<option value="' . (int) $a['id'] . '">' . esc_html( self::fa( $a['code'] ) . ' — ' . $a['name'] ) . '</option>';
				}
			}
			echo '</select></label>'; ModuleController::input('code','کد (۳ رقم کل، ۵ رقم معین)','','text','required inputmode="numeric" maxlength="5" dir="ltr"');
			ModuleController::input('name','نام حساب','','text','required maxlength="190"');
			echo '<label>تفصیلی<select name="detail_rule"><option value="none">ندارد</option><option value="optional">اختیاری</option><option value="required">الزامی</option></select></label>';
			echo '<button class="btn btn-primary" type="submit">افزودن</button></form></div>';
		}


		if($canEdit && ($edit=Request::int('get','edit'))) {
			$item=AccountService::get($cid,$edit);
			echo '<section class="erp-card"><h3>ویرایش حساب</h3><form method="post" class="erp-form">';
			ModuleController::form('module_account_update',$cid,'accounts',$edit);
			ModuleController::input('code','کد حساب',$item['code'],'text','required maxlength="5"');
			ModuleController::input('name','نام حساب',$item['name'],'text','required maxlength="190"');
			$options=[];foreach(ChartSeed::classes() as $k=>$meta) $options[$k]=$meta[0];
			ModuleController::select('stmt_class','طبقه صورت مالی',$options,$item['stmt_class']);
			ModuleController::select('detail_rule','قاعده تفصیلی',['none'=>'ممنوع','optional'=>'اختیاری','required'=>'الزامی'],$item['detail_rule']);
			ModuleController::select('detail_kind','نوع تفصیلی',[''=>'همه']+\Agah\Erp\Modules\Accounting\DetailService::KINDS,$item['detail_kind']);
			echo '<p>ساختار حساب سیستمی یا دارای گردش تغییر نمی‌کند؛ تغییر نام مجاز است.</p><button class="btn btn-primary" type="submit">ذخیره ویرایش</button></form></section>';
		}
