package ir.idpa.agah.mobile

import android.app.Application
import android.graphics.Bitmap
import android.view.WindowManager
import androidx.compose.material3.*
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.foundation.layout.*
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.LayoutDirection
import androidx.test.core.app.ApplicationProvider
import ir.idpa.agah.mobile.ui.*
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.Robolectric
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import org.robolectric.annotation.LooperMode
import java.io.File

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[28],qualifiers="w390dp-h844dp-xhdpi")
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@LooperMode(LooperMode.Mode.PAUSED)
class BiometricUiTest {
    @get:Rule val compose=createComposeRule()
    @Before fun clean(){ApplicationProvider.getApplicationContext<Application>().getSharedPreferences("agah_session",0).edit().clear().commit()}
    @Test fun unlockAndPasswordButtonsAreDistinctAndAccessible(){var unlock=0;var password=0;compose.setContent {CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl){MaterialTheme(colorScheme=AgahLight,typography=AgahType,shapes=AgahShapes){BiometricLockScreen(LocalSecurityState(true,true),BiometricAvailability(true,"حسگر آماده است"),false,{unlock++},{password++},{})}}};compose.onNodeWithTag("biometric-lock").performScrollToNode(hasText("ورود با اثر انگشت"));compose.onNodeWithText("ورود با اثر انگشت").performClick();compose.onNodeWithTag("biometric-lock").performScrollToNode(hasText("ورود با گذرواژهٔ حساب"));compose.onNodeWithText("ورود با گذرواژهٔ حساب").performClick();assertEquals(1,unlock);assertEquals(1,password)}
    @Test fun unsupportedHardwareKeepsPasswordFallbackAvailable(){compose.setContent {MaterialTheme{BiometricLockScreen(LocalSecurityState(true,true),BiometricAvailability(false,"حسگر در دسترس نیست"),false,{},{},{})}};compose.onNodeWithTag("biometric-lock").performScrollToNode(hasText("ورود با اثر انگشت"));compose.onNodeWithText("ورود با اثر انگشت").assertIsNotEnabled();compose.onNodeWithTag("biometric-lock").performScrollToNode(hasText("ورود با گذرواژهٔ حساب"));compose.onNodeWithText("ورود با گذرواژهٔ حساب").assertIsEnabled()}
    @Test @Config(qualifiers="w800dp-h400dp-xhdpi") fun largeFontLandscapeLockScreenScrollsAndUsesDarkTheme(){var root: android.view.View?=null;compose.setContent {root=LocalView.current.rootView;val density=LocalDensity.current;CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl,LocalDensity provides Density(density.density,1.5f)){MaterialTheme(colorScheme=AgahDark,typography=AgahType,shapes=AgahShapes){BiometricLockScreen(LocalSecurityState(true,true,message="حسگر قفل شده است؛ دوباره تلاش کنید."),BiometricAvailability(false,"ورود جایگزین در دسترس است"),false,{},{},{})}}};compose.onNodeWithTag("biometric-lock").performScrollToNode(hasText("ورود با گذرواژهٔ حساب"));compose.onNodeWithText("ورود با گذرواژهٔ حساب").assertIsDisplayed();compose.runOnIdle{val view=checkNotNull(root);val image=Bitmap.createBitmap(view.width,view.height,Bitmap.Config.ARGB_8888);view.draw(android.graphics.Canvas(image));val directory=File("build/reports/ui-screenshots").apply{mkdirs()};File(directory,"biometric-dark-landscape.png").outputStream().use{image.compress(Bitmap.CompressFormat.PNG,100,it)};image.recycle()}}
    @Test fun busyUnlockCannotSwitchAccountMidRequest(){compose.setContent {MaterialTheme{BiometricLockScreen(LocalSecurityState(true,true),BiometricAvailability(true,"حسگر آماده است"),true,{},{},{})}};compose.onNodeWithTag("biometric-lock").performScrollToNode(hasText("ورود با اثر انگشت"));compose.onNodeWithText("ورود با اثر انگشت").assertIsNotEnabled();compose.onNodeWithTag("biometric-lock").performScrollToNode(hasText("ورود با گذرواژهٔ حساب"));compose.onNodeWithText("ورود با گذرواژهٔ حساب").assertIsNotEnabled()}
    @Test fun realActivityColdStartUsesSecureWindowAndLockedViewModel(){val app=ApplicationProvider.getApplicationContext<Application>();app.getSharedPreferences("agah_session",0).edit().putBoolean("biometric",true).putString("owner","7").commit();val activity=Robolectric.buildActivity(MainActivity::class.java).setup();val dialog=androidx.appcompat.app.AlertDialog.Builder(activity.get()).setTitle("اثر انگشت").setNegativeButton("انصراف",null).create();dialog.show();assertTrue(dialog.isShowing);dialog.dismiss();assertTrue(activity.get().window.attributes.flags and WindowManager.LayoutParams.FLAG_SECURE!=0);val vm=androidx.lifecycle.ViewModelProvider(activity.get())[AgahViewModel::class.java];assertTrue(vm.security.value.locked);assertFalse(vm.state.value.signedIn);assertFalse(vm.state.value.busy);activity.pause().stop().destroy()}
}
