#include <metal_stdlib>
using namespace metal;

// Ordered-Bayer 4x4 dithering against the 4-color Game Boy LCD
// palette. Applied via SwiftUI's .colorEffect(ShaderLibrary....).
//
// SwiftUI passes:
//   - position: pixel coordinate in the destination view
//   - color:    the source pixel color (premultiplied alpha)
//
// We compute luminance, add a Bayer-matrix bias, and snap to one of
// four palette colors.

[[ stitchable ]] half4 gameBoyDither(float2 position, half4 color) {
    // Bayer 4x4 matrix (values 0..15, normalized to 0..1 below).
    constexpr int bayer[16] = {
         0,  8,  2, 10,
        12,  4, 14,  6,
         3, 11,  1,  9,
        15,  7, 13,  5
    };

    int bx = int(position.x) & 3;
    int by = int(position.y) & 3;
    float threshold = float(bayer[by * 4 + bx]) / 16.0;

    // Luminance (Rec. 601).
    float lum = dot(float3(color.rgb), float3(0.299, 0.587, 0.114));

    // Add threshold-quarter so dithering acts as a small offset.
    float biased = lum + (threshold - 0.5) * 0.25;

    // Quantize to 4 buckets.
    int bucket = clamp(int(biased * 4.0), 0, 3);

    // Game Boy palette (light → dark).
    const half3 palette[4] = {
        half3(0.608, 0.737, 0.059),   // 0x9BBC0F
        half3(0.545, 0.675, 0.059),   // 0x8BAC0F
        half3(0.188, 0.384, 0.188),   // 0x306230
        half3(0.059, 0.220, 0.059)    // 0x0F380F
    };

    half3 out = palette[bucket];
    return half4(out, color.a);
}
