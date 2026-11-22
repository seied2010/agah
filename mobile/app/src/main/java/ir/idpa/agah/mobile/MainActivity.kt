package ir.idpa.agah.mobile

import android.os.Bundle
import android.view.WindowManager
import androidx.fragment.app.FragmentActivity
import androidx.activity.viewModels
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.ProcessLifecycleOwner
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge

import ir.idpa.agah.mobile.ui.SecureAgahApp
import ir.idpa.agah.mobile.ui.AgahViewModel
import ir.idpa.agah.mobile.ui.BiometricHost

class MainActivity : FragmentActivity() {
    private val model: AgahViewModel by viewModels()
    private lateinit var biometrics: BiometricHost
    private val background=LifecycleEventObserver {_,event->if(event==Lifecycle.Event.ON_STOP) {biometrics.cancel();model.lockForBackground()} }
    override fun onDestroy() {
        ProcessLifecycleOwner.get().lifecycle.removeObserver(background)
        if(::biometrics.isInitialized)biometrics.cancel()
        super.onDestroy()
    }
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
        enableEdgeToEdge()
        biometrics=BiometricHost(this,model)
        ProcessLifecycleOwner.get().lifecycle.addObserver(background)
        setContent { SecureAgahApp(model,biometrics) }
    }
}
