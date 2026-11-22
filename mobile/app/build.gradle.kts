plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}
val productionKeys=listOf("AGAH_RELEASE_KEYSTORE","AGAH_RELEASE_STORE_PASSWORD","AGAH_RELEASE_KEY_ALIAS","AGAH_RELEASE_KEY_PASSWORD").associateWith { System.getenv(it) }
require(productionKeys.values.all { it.isNullOrBlank() }||productionKeys.values.all { !it.isNullOrBlank() }) { "Provide all four AGAH_RELEASE signing variables together; never commit signing secrets." }
android {
    namespace = "ir.idpa.agah.mobile"
    compileSdk = 35
    defaultConfig {
        applicationId = "ir.idpa.agah.mobile"
        minSdk = 26
        targetSdk = 35
        versionCode = 7
        versionName = "0.5.1-biometric-rc1"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }
    if(!productionKeys["AGAH_RELEASE_KEYSTORE"].isNullOrBlank()) signingConfigs.create("production") {
        storeFile=file(productionKeys.getValue("AGAH_RELEASE_KEYSTORE")!!)
        storePassword=productionKeys.getValue("AGAH_RELEASE_STORE_PASSWORD")
        keyAlias=productionKeys.getValue("AGAH_RELEASE_KEY_ALIAS")
        keyPassword=productionKeys.getValue("AGAH_RELEASE_KEY_PASSWORD")
    }
    buildTypes {
        release {
            isMinifyEnabled = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            signingConfig=signingConfigs.findByName("production")
        }
        create("acceptance") {
            initWith(getByName("release"))
            isDebuggable=false
            signingConfig=signingConfigs.getByName("debug")
            versionNameSuffix="-acceptance"
            matchingFallbacks += listOf("release")
        }
    }
    signingConfigs.getByName("debug") {
        storeFile = file(System.getenv("AGAH_DEBUG_KEYSTORE") ?: "${rootProject.projectDir}/.local/debug.keystore")
    }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
    kotlinOptions { jvmTarget = "17" }
    buildFeatures { compose = true; buildConfig = true }
    testOptions { unitTests.isReturnDefaultValues = true; unitTests.isIncludeAndroidResources = true }
    testBuildType=providers.gradleProperty("agahTestBuildType").getOrElse("debug")
    adbOptions { timeOutInMs = 180000 }
}
dependencies {
    implementation(platform("androidx.compose:compose-bom:2025.04.01"))
    implementation("androidx.activity:activity-compose:1.10.1")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    implementation("androidx.compose.ui:ui-tooling-preview")
    debugImplementation("androidx.compose.ui:ui-tooling")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.7")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.7")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.1")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.biometric:biometric:1.1.0")
    implementation("androidx.lifecycle:lifecycle-process:2.8.7")
    implementation("com.journeyapps:zxing-android-embedded:4.3.0")
    testImplementation("junit:junit:4.13.2")
    testImplementation("androidx.appcompat:appcompat:1.2.0")
    testImplementation("org.robolectric:robolectric:4.14.1")
    testImplementation("androidx.compose.ui:ui-test-junit4")
    testImplementation("androidx.test:core:1.6.1")
    testImplementation("org.json:json:20240303")
    testImplementation("com.squareup.okhttp3:mockwebserver:4.12.0")
    androidTestImplementation(platform("androidx.compose:compose-bom:2025.04.01"))
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    androidTestImplementation("com.squareup.okhttp3:mockwebserver:4.12.0")
    debugImplementation("androidx.compose.ui:ui-test-manifest")
}
