# google_mlkit_text_recognition trae soporte opcional para chino/
# devanagari/japones/coreano como dependencias "por si acaso", pero la
# app solo usa TextRecognitionScript.latin - esas clases nunca se
# cargan en tiempo de ejecucion, asi que no hace falta que R8 las
# encuentre, solo que no falle el build por no encontrarlas.
-dontwarn com.google.mlkit.vision.text.chinese.ChineseTextRecognizerOptions$Builder
-dontwarn com.google.mlkit.vision.text.chinese.ChineseTextRecognizerOptions
-dontwarn com.google.mlkit.vision.text.devanagari.DevanagariTextRecognizerOptions$Builder
-dontwarn com.google.mlkit.vision.text.devanagari.DevanagariTextRecognizerOptions
-dontwarn com.google.mlkit.vision.text.japanese.JapaneseTextRecognizerOptions$Builder
-dontwarn com.google.mlkit.vision.text.japanese.JapaneseTextRecognizerOptions
-dontwarn com.google.mlkit.vision.text.korean.KoreanTextRecognizerOptions$Builder
-dontwarn com.google.mlkit.vision.text.korean.KoreanTextRecognizerOptions

# ML Kit (texto y etiquetado de imagen) y Play Services usan reflection
# internamente para inicializar sus modelos - R8 puede "achicar"/renombrar
# esas clases sin darse cuenta de que hacen falta en tiempo real, aunque
# compile sin errores. Caso real: NullPointerException
# ("getClass() on a null object reference") leyendo una etiqueta en el
# APK instalado, con compilacion 100% limpia - se soluciona excluyendo
# estas librerias de la poda/renombrado de R8 por completo.
-keep class com.google.mlkit.** { *; }
-keep class com.google.android.gms.** { *; }
-dontwarn com.google.mlkit.**
-dontwarn com.google.android.gms.**

# Ademas, se desactivan las optimizaciones agresivas de R8 en general
# (no solo el keep de ML Kit) - esta version de Android Gradle Plugin
# obliga a partir del archivo "-optimize" (ver build.gradle.kts), pero
# esta linea anula esa parte especifica y deja el comportamiento mas
# conservador que evito el crash.
-dontoptimize
