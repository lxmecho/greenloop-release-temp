from pathlib import Path

from PIL import Image, ImageDraw, ImageFont


WORKSPACE = Path(r"d:\桌面\课程\节能减排大赛")
QR_PATH = WORKSPACE / "assets" / "images" / "site-qrcode.png"
POSTER_PATH = WORKSPACE / "assets" / "images" / "site-qrcode-poster.png"
SITE_URL = "https://134139.xyz/"


def get_font(size: int, bold: bool = False):
    candidates = []
    if bold:
        candidates.extend(
            [
                r"C:\Windows\Fonts\msyhbd.ttc",
                r"C:\Windows\Fonts\simhei.ttf",
            ]
        )
    candidates.extend(
        [
            r"C:\Windows\Fonts\msyh.ttc",
            r"C:\Windows\Fonts\simsun.ttc",
        ]
    )
    for path in candidates:
        if Path(path).exists():
            return ImageFont.truetype(path, size)
    return ImageFont.load_default()


def centered_text(draw, text, y, font, fill, canvas_width):
    bbox = draw.textbbox((0, 0), text, font=font)
    text_width = bbox[2] - bbox[0]
    x = (canvas_width - text_width) / 2
    draw.text((x, y), text, font=font, fill=fill)
    return bbox[3] - bbox[1]


def main():
    canvas_width = 1200
    canvas_height = 1450
    background = Image.new("RGB", (canvas_width, canvas_height), "#F7FBF8")
    draw = ImageDraw.Draw(background)

    draw.rounded_rectangle((70, 70, 1130, 1530), radius=36, fill="#FFFFFF", outline="#D7E7DD", width=4)
    draw.rounded_rectangle((70, 70, 1130, 320), radius=36, fill="#EAF5EE")

    title_font = get_font(68, bold=True)
    subtitle_font = get_font(34, bold=False)
    url_font = get_font(28, bold=False)
    footer_font = get_font(30, bold=False)

    centered_text(draw, "扫码访问网站", 135, title_font, "#155A39", canvas_width)
    centered_text(draw, "绿循校园", 225, subtitle_font, "#2F6D4D", canvas_width)

    qr = Image.open(QR_PATH).convert("RGB")
    qr = qr.resize((620, 620))
    qr_bg = Image.new("RGB", (700, 700), "#FFFFFF")
    qr_bg_draw = ImageDraw.Draw(qr_bg)
    qr_bg_draw.rounded_rectangle((0, 0, 699, 699), radius=28, fill="#FFFFFF", outline="#D6E7DC", width=4)
    qr_bg.paste(qr, (40, 40))
    background.paste(qr_bg, (250, 410))

    centered_text(draw, "扫描二维码即可进入网站", 1160, subtitle_font, "#334155", canvas_width)
    centered_text(draw, SITE_URL, 1225, url_font, "#0F766E", canvas_width)

    POSTER_PATH.parent.mkdir(parents=True, exist_ok=True)
    background.save(POSTER_PATH)


if __name__ == "__main__":
    main()
