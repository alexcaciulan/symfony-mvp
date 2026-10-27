"""Give every table of a pandoc .docx visible borders and a shaded header row."""
import re, sys, zipfile, shutil, os

src = sys.argv[1]
tmp = src + '.tmp'
borders = '<w:tblBorders>' + ''.join(
    '<w:%s w:val="single" w:sz="4" w:space="0" w:color="A6A6A6"/>' % e
    for e in ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']) + '</w:tblBorders>'
margins = ('<w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:left w:w="100" w:type="dxa"/>'
           '<w:bottom w:w="60" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar>')

def scale_grid(t, text_width):
    # Stretch the declared column widths to the text width, keeping their ratio,
    # so viewers that follow the grid (not the 100% table width) fill the page too.
    cols = [int(w) for w in re.findall(r'<w:gridCol w:w="(\d+)"', t)]
    if not cols or not text_width:
        return t
    total = sum(cols)
    scaled = iter([round(c * text_width / total) for c in cols])
    return re.sub(r'<w:gridCol w:w="\d+"', lambda m: '<w:gridCol w:w="%d"' % next(scaled), t)


def text_width_of(xml):
    page = re.search(r'<w:pgSz w:w="(\d+)"', xml)
    margins = re.search(r'<w:pgMar[^>]*w:right="(\d+)"[^>]*w:left="(\d+)"', xml) or re.search(r'<w:pgMar[^>]*w:left="(\d+)"[^>]*w:right="(\d+)"', xml)
    if not page or not margins:
        return 9360  # US Letter with 1 inch margins, pandoc's default
    return int(page.group(1)) - int(margins.group(1)) - int(margins.group(2))


def fix_table(t):
    t = re.sub(r'(<w:tblPr>.*?)(<w:tblLook)', lambda m: m.group(1) + borders + margins + m.group(2), t, count=1, flags=re.S)
    # shade the cells of the first row
    first = re.search(r'<w:tr>.*?</w:tr>', t, re.S)
    if first:
        row = first.group(0)
        row2 = re.sub(r'<w:tcPr>', '<w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="E7EEF7"/>', row)
        row2 = re.sub(r'<w:tc>(?!<w:tcPr>)', '<w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="E7EEF7"/></w:tcPr>', row2)
        row2 = re.sub(r'<w:r>(?!<w:rPr>)', '<w:r><w:rPr><w:b/></w:rPr>', row2)
        row2 = re.sub(r'<w:r><w:rPr>(?!<w:b/>)', '<w:r><w:rPr><w:b/>', row2)
        t = t.replace(row, row2, 1)
    return t

with zipfile.ZipFile(src) as zin, zipfile.ZipFile(tmp, 'w', zipfile.ZIP_DEFLATED) as zout:
    for item in zin.infolist():
        data = zin.read(item.filename)
        if item.filename == 'word/document.xml':
            xml = data.decode('utf-8')
            width = text_width_of(xml)
            xml = re.sub(r'<w:tbl>.*?</w:tbl>', lambda m: scale_grid(fix_table(m.group(0)), width), xml, flags=re.S)
            data = xml.encode('utf-8')
        zout.writestr(item, data)
shutil.move(tmp, src)
print('tables styled')
