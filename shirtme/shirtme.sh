#!/bin/bash

# ==== USER INPUT ====
TEXT="Your Text Here"
FONT="Arial"
POINTSIZE=72

# ==== FILES ====
SHIRT_IMAGE="shirts/shirt003.png"
DISPLACE_MAP="shirts/map003.png"
TEXT_IMAGE="shirts/text003.png"
WARPED_TEXT="warped_text.png"
FINAL_OUTPUT="final_output.png"

# 1. Create transparent text image
# convert -background none -fill black -font "$FONT" -pointsize "$POINTSIZE" \
#         label:"$TEXT" "$TEXT_IMAGE"

# 2. Warp the text using the displacement map
composite -compose Displace -displace 20x20 "$DISPLACE_MAP" "$TEXT_IMAGE" "$WARPED_TEXT"

# 3. Composite the warped text onto the T-shirt (use Multiply for a printed look)
composite -compose Multiply "$WARPED_TEXT" "$SHIRT_IMAGE" "$FINAL_OUTPUT"

echo "✅ Done! Output saved as $FINAL_OUTPUT"