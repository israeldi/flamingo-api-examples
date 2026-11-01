"""Original tiny two-sheet workbook; no customer or real financial data."""
import io
import zipfile
from xml.sax.saxutils import escape

MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
PKG = 'http://schemas.openxmlformats.org/package/2006/relationships'


def create_sample():
    formulas = [('A1','Inputs!A1+Inputs!B1','999'),('A2','A1*2','999'),
                ('A3','IF(A1=12,"ready","wrong")',None),('A4','A1=12',None),
                ('A5','""',None),('A6','1/0',None),('A7','DATE(2026,11,1)',None),
                ('A8','ROUND(0.1+0.2,2)',None),('A9','"#REF!"',None),('A10','"café"',None)]
    rows = ''.join('<row r="'+str(i)+'"><c r="'+ref+'"><f>'+escape(formula)+'</f>'
                   + ('' if cache is None else '<v>'+cache+'</v>') + '</c></row>'
                   for i,(ref,formula,cache) in enumerate(formulas,1))
    parts = {
      '[Content_Types].xml': '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
      '_rels/.rels': '<Relationships xmlns="'+PKG+'"><Relationship Id="rId1" Type="'+REL+'/officeDocument" Target="xl/workbook.xml"/></Relationships>',
      'xl/workbook.xml': '<workbook xmlns="'+MAIN+'" xmlns:r="'+REL+'"><sheets><sheet name="Inputs" sheetId="1" r:id="rId1"/><sheet name="Results" sheetId="2" r:id="rId2"/></sheets><calcPr calcId="0" calcMode="manual" fullCalcOnLoad="0"/></workbook>',
      'xl/_rels/workbook.xml.rels': '<Relationships xmlns="'+PKG+'"><Relationship Id="rId1" Type="'+REL+'/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="'+REL+'/worksheet" Target="worksheets/sheet2.xml"/></Relationships>',
      'xl/worksheets/sheet1.xml': '<worksheet xmlns="'+MAIN+'"><sheetData><row r="1"><c r="A1"><v>10</v></c><c r="B1"><v>2</v></c></row></sheetData></worksheet>',
      'xl/worksheets/sheet2.xml': '<worksheet xmlns="'+MAIN+'"><sheetData>'+rows+'</sheetData></worksheet>',
    }
    output = io.BytesIO()
    with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as archive:
        for name,content in parts.items():
            info=zipfile.ZipInfo(name,date_time=(2026,11,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED
            archive.writestr(info,content.encode('utf-8'))
    return output.getvalue()


if __name__ == '__main__':
    import argparse, hashlib, os
    from pathlib import Path
    parser = argparse.ArgumentParser(description='Create one original fictional CalcReceipt example; no upload.')
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    data = create_sample()
    descriptor = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0), 0o600)
    with os.fdopen(descriptor, 'wb') as handle:
        handle.write(data)
    print('Created fictional workbook; bytes=' + str(len(data)) + '; sha256=' + hashlib.sha256(data).hexdigest())
