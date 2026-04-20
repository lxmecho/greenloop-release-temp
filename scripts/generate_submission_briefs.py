from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor


ROOT = Path(r"d:\桌面\课程\节能减排大赛")
OUTPUT_DIR = ROOT / "最终提交版"


def set_run_font(run, size=12, bold=False, color="000000"):
    run.font.name = "Microsoft YaHei"
    run._element.rPr.rFonts.set(qn("w:eastAsia"), "Microsoft YaHei")
    run.font.size = Pt(size)
    run.bold = bold
    run.font.color.rgb = RGBColor.from_string(color)


def set_paragraph_border(paragraph, color="9DB8A7", size="8", space="4"):
    p_pr = paragraph._p.get_or_add_pPr()
    p_bdr = p_pr.find(qn("w:pBdr"))
    if p_bdr is None:
        p_bdr = OxmlElement("w:pBdr")
        p_pr.append(p_bdr)

    for edge in ("top", "left", "bottom", "right"):
        element = p_bdr.find(qn(f"w:{edge}"))
        if element is None:
            element = OxmlElement(f"w:{edge}")
            p_bdr.append(element)
        element.set(qn("w:val"), "single")
        element.set(qn("w:sz"), size)
        element.set(qn("w:space"), space)
        element.set(qn("w:color"), color)


def apply_base_styles(doc):
    section = doc.sections[0]
    section.top_margin = Cm(2.4)
    section.bottom_margin = Cm(2.2)
    section.left_margin = Cm(2.4)
    section.right_margin = Cm(2.4)

    normal = doc.styles["Normal"]
    normal.font.name = "Microsoft YaHei"
    normal._element.rPr.rFonts.set(qn("w:eastAsia"), "Microsoft YaHei")
    normal.font.size = Pt(12)


def add_title(doc, text, subtitle=None):
    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.space_after = Pt(10)
    run = p.add_run(text)
    set_run_font(run, size=20, bold=True, color="0F3D2B")

    if subtitle:
        p2 = doc.add_paragraph()
        p2.alignment = WD_ALIGN_PARAGRAPH.CENTER
        p2.paragraph_format.space_after = Pt(18)
        run2 = p2.add_run(subtitle)
        set_run_font(run2, size=11, color="5A6E63")


def add_heading(doc, text):
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(10)
    p.paragraph_format.space_after = Pt(5)
    run = p.add_run(text)
    set_run_font(run, size=13.5, bold=True, color="0F3D2B")


def add_paragraph(doc, text):
    p = doc.add_paragraph()
    p.paragraph_format.first_line_indent = Cm(0.74)
    p.paragraph_format.line_spacing = 1.45
    p.paragraph_format.space_after = Pt(5)
    run = p.add_run(text)
    set_run_font(run, size=12)


def add_bullets(doc, items):
    for item in items:
        p = doc.add_paragraph(style="List Bullet")
        p.paragraph_format.line_spacing = 1.35
        p.paragraph_format.space_after = Pt(2)
        run = p.add_run(item)
        set_run_font(run, size=12)


def add_shot_note(doc, pages, content):
    p = doc.add_paragraph()
    p.paragraph_format.left_indent = Cm(0.3)
    p.paragraph_format.right_indent = Cm(0.3)
    p.paragraph_format.line_spacing = 1.3
    p.paragraph_format.space_before = Pt(4)
    p.paragraph_format.space_after = Pt(8)
    set_paragraph_border(p, color="B7C9BC")

    r1 = p.add_run("建议截图页面：")
    set_run_font(r1, size=11, bold=True, color="0F3D2B")
    r2 = p.add_run(pages + "\n")
    set_run_font(r2, size=11)

    r3 = p.add_run("建议截取内容：")
    set_run_font(r3, size=11, bold=True, color="0F3D2B")
    r4 = p.add_run(content)
    set_run_font(r4, size=11)


def build_site_intro():
    doc = Document()
    apply_base_styles(doc)
    add_title(doc, "绿循校园网站简介", "提交材料精简版")

    add_heading(doc, "一、网站定位")
    add_paragraph(
        doc,
        "绿循校园是一个面向校园场景的电子废物规范处理网站，围绕“固定回收点投放、上门回收”两种处理方式，构建了从用户提交、管理员审核到结果反馈的基本业务闭环。",
    )

    add_heading(doc, "二、服务对象")
    add_paragraph(
        doc,
        "网站主要服务于校内学生和管理人员。普通用户可以在线提交损坏或老旧电子产品、查看处理进度；管理员可以在后台完成审核、回收安排、公告发布和积分管理等工作。",
    )

    add_heading(doc, "三、网站特点")
    add_bullets(
        doc,
        [
            "围绕校园电子废物处理场景设计，主题明确，使用门槛低。",
            "将回收点投放、上门回收、通知和积分激励整合到同一平台中。",
            "同时支持用户端操作与管理员端管理，具备完整的网站运行逻辑。",
        ],
    )
    add_shot_note(
        doc,
        "首页",
        "网站名称、主标题、两种处理方式入口或简介区域，以及页面整体视觉效果。",
    )

    add_heading(doc, "四、建设意义")
    add_paragraph(
        doc,
        "该网站能够为校园内老旧或损坏电子设备提供更规范的回收路径，提升电子废物处理效率，体现绿色校园建设的实际应用价值。",
    )

    return doc


def build_feature_intro():
    doc = Document()
    apply_base_styles(doc)
    add_title(doc, "绿循校园核心功能简介", "提交材料精简版")

    add_heading(doc, "一、用户注册与登录")
    add_paragraph(
        doc,
        "用户可通过手机号完成注册与登录，进入网站后即可使用提交物品、查看进度和积分兑换等功能。这一模块是整个平台业务入口的基础。",
    )
    add_shot_note(
        doc,
        "登录 / 注册页",
        "登录表单、注册表单，以及手机号注册这一入口形式。",
    )

    add_heading(doc, "二、电子产品提交")
    add_paragraph(
        doc,
        "用户可在线填写电子产品名称、类别、状态、图片等信息，并选择“固定回收点投放”或“上门回收”中的一种处理方式。该功能是网站最核心的业务入口。",
    )
    add_shot_note(
        doc,
        "提交物品页",
        "物品基础信息表单、图片上传区域，以及两种处理方式的选择内容。",
    )

    add_heading(doc, "三、固定回收点投放")
    add_paragraph(
        doc,
        "用户可选择固定回收点方式处理电子废物，填写校区、园区、点位和预计投放时间。管理员审核通过后，用户按编号完成投递，管理员再统一回收并完成核验。",
    )
    add_shot_note(
        doc,
        "提交物品页中的固定回收点区域，或物品详情页",
        "校区、园区、具体点位、预计投放时间，以及回收安排展示内容。",
    )

    add_heading(doc, "四、上门回收")
    add_paragraph(
        doc,
        "用户可填写宿舍楼栋、楼层、房间号和可预约时间段，管理员审核通过后按预约安排上门取走。双方完成“已取走”确认后，再进入最终核验和积分发放环节。",
    )
    add_shot_note(
        doc,
        "提交物品页中的上门回收区域，或管理员后台“上门回收”页",
        "宿舍信息、预约时段、待处理订单列表和处理按钮。",
    )

    add_heading(doc, "五、管理员审核与通知积分")
    add_paragraph(
        doc,
        "管理员可在后台审核用户提交信息，推进回收流程，并通过站内消息向用户反馈审核结果和处理进度。流程完成后系统会自动发放积分，积分可用于后续兑换。",
    )
    add_shot_note(
        doc,
        "管理员后台“物品审核”页，配合个人中心页或积分商城页",
        "待审核列表、审核按钮、站内消息列表、当前积分显示，以及积分兑换相关区域。",
    )

    return doc


def build_feature_doc():
    doc = Document()
    apply_base_styles(doc)
    add_title(doc, "绿循校园网站功能文档", "用户端与管理员端精简说明版")

    add_heading(doc, "一、平台概述")
    add_paragraph(
        doc,
        "绿循校园是一个面向校园电子废物处理场景的网站平台，围绕固定回收点投放和上门回收两类核心业务，构建了从用户提交、管理员审核、进度通知到积分激励的完整闭环。",
    )
    add_shot_note(
        doc,
        "首页",
        "网站主标题、两种处理方式简介区、导航栏和公告区。",
    )

    add_heading(doc, "二、角色与入口")
    add_paragraph(
        doc,
        "平台包含普通用户与管理员两类角色。普通用户通过首页的“登录 / 注册”入口进入前台模块；管理员通过隐藏后台入口登录后，可处理审核、回收流程、公告和积分兑换。",
    )
    add_shot_note(
        doc,
        "登录 / 注册页，以及管理员登录页",
        "普通用户登录注册区域、管理员隐藏登录入口页面。",
    )

    add_heading(doc, "三、用户端核心功能")
    add_bullets(
        doc,
        [
            "提交物品：填写物品名称、类别、状态、图片和处理方式，是最核心的业务入口。",
            "固定回收点：补充校区、园区、点位和预计投放时间，审核通过后按编号完成投递。",
            "上门回收：补充宿舍信息和预约时段，管理员上门取走后进入核验流程。",
            "个人中心与积分兑换：查看提交记录、站内通知、当前积分和兑换记录。",
        ],
    )
    add_shot_note(
        doc,
        "提交物品页、个人中心页、积分商城页",
        "基础信息表单、两种处理方式区域、消息列表、当前积分和兑换商品区域。",
    )

    add_heading(doc, "四、管理员端核心功能")
    add_bullets(
        doc,
        [
            "物品审核：核对用户提交的图片和说明，决定通过或驳回。",
            "上门回收处理：查看待上门回收订单，完成取走确认并推进核验。",
            "回收点与公告管理：维护回收点信息和首页公告内容。",
            "积分兑换处理：审核兑换申请，决定发放或驳回。",
        ],
    )
    add_shot_note(
        doc,
        "管理员后台“物品审核”页，必要时补充“上门回收”页",
        "待审核列表、处理按钮、回收流程状态、公告或兑换管理区域。",
    )

    add_heading(doc, "五、总结")
    add_paragraph(
        doc,
        "系统已形成“提交 - 审核 - 回收 - 通知 - 积分兑换”的业务闭环，能够较直观展示校园电子废物处理场景下的网站设计思路和实现效果。",
    )

    return doc


def main():
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    root_feature_doc_path = ROOT / "网站功能文档-用户端与管理员端.docx"
    feature_doc_path = OUTPUT_DIR / "01-网站功能文档.docx"
    site_intro_path = OUTPUT_DIR / "06-网站简介.docx"
    feature_intro_path = OUTPUT_DIR / "07-核心功能简介.docx"

    feature_doc = build_feature_doc()
    feature_doc.save(root_feature_doc_path)
    feature_doc.save(feature_doc_path)
    build_site_intro().save(site_intro_path)
    build_feature_intro().save(feature_intro_path)

    print(root_feature_doc_path)
    print(feature_doc_path)
    print(site_intro_path)
    print(feature_intro_path)


if __name__ == "__main__":
    main()
