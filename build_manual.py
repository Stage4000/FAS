from pathlib import Path
from reportlab.pdfgen import canvas
from reportlab.platypus import Paragraph, Table, TableStyle
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.colors import HexColor, white
from reportlab.lib.enums import TA_LEFT

OUT=Path(r'C:/Users/admin/Downloads/GM master key set manual/GM-3067-Retypeset-Partial-Manual.pdf')
C=canvas.Canvas(str(OUT),pagesize=(612,792))
C.setTitle('General Motors Master Key Set No. 3067 | Retypeset partial manual')
C.setAuthor('Curtis Industries, Inc. (original text); digital reconstruction from supplied photographs')
ink=HexColor('#172B39'); gray=HexColor('#54616A'); light=HexColor('#EDF2F4'); rule=HexColor('#CAD4DA')
body=ParagraphStyle('body',fontName='Helvetica',fontSize=10.6,leading=15,textColor=ink,spaceAfter=9)
small=ParagraphStyle('small',parent=body,fontSize=9,leading=13,textColor=gray)
y=0
def para(text,style=body):
    global y
    p=Paragraph(text,style); w,h=p.wrap(504,720)
    p.drawOn(C,54,y-h); y-=h+style.spaceAfter
def heading(text):
    global y
    y-=9
    para(text,ParagraphStyle('h',parent=body,fontName='Helvetica-Bold',fontSize=13,leading=17,spaceAfter=9))
def start(n,title,source):
    global y
    C.setFillColor(ink);C.rect(0,752,612,40,fill=1,stroke=0)
    C.setFillColor(white);C.setFont('Helvetica-Bold',9);C.drawString(54,768,'CURTIS  /  GM MASTER KEY SET NO. 3067')
    C.setFillColor(ink);C.setFont('Helvetica-Bold',23);C.drawString(54,706,title)
    C.setStrokeColor(rule);C.line(54,686,558,686)
    C.setFillColor(gray);C.setFont('Helvetica',8);C.drawString(54,35,source);C.drawRightString(558,35,str(n))
    C.bookmarkPage('p'+str(n)); C.addOutlineEntry(title,'p'+str(n),0,False)
    y=663
def end(): C.showPage()
def table(columns,letters=None,compact=False):
    global y
    if letters is None: letters='ABCDEFGHIJKLM'[:max(map(len,columns))]
    data=[['Row','1st','2nd','3rd','4th','5th']]
    for i,L in enumerate(letters):data.append([L]+[col[i] if i<len(col) else '' for col in columns])
    t=Table(data,colWidths=[44]+[92]*5,rowHeights=17 if compact else 20)
    t.setStyle(TableStyle([
        ('BACKGROUND',(0,0),(-1,0),ink),('TEXTCOLOR',(0,0),(-1,0),white),
        ('FONTNAME',(0,0),(-1,0),'Helvetica-Bold'),('FONTSIZE',(0,0),(-1,0),9),
        ('FONTNAME',(0,1),(0,-1),'Helvetica-Bold'),('FONTNAME',(1,1),(-1,-1),'Courier-Bold'),
        ('FONTSIZE',(0,1),(-1,-1),10),('TEXTCOLOR',(0,1),(-1,-1),ink),
        ('ROWBACKGROUNDS',(0,1),(-1,-1),[white,light]),('ALIGN',(0,0),(-1,-1),'CENTER'),
        ('VALIGN',(0,0),(-1,-1),'MIDDLE'),('LINEBELOW',(0,-1),(-1,-1),.5,rule),
    ]))
    w,h=t.wrap(504,700);t.drawOn(C,54,y-h);y-=h+10

# Codes are transcribed from the user's printed page 3, in original column order.
codes={
'GM-1':[
'444223 444322 454222 444323 544222 454223 454232 544232 445332 454323 554222 454332 544332',
'544223 444233 454322 445323 544322 554223 454233 554232 455332 454333 554322',
'544323 544233 455323 455233 545322 554323 554233 554332',
'545323 544333 555322 555323 545332',
'545333 554333 555332'],
'GM-2':[
'222445 223444 232444 222454 232454 322444 223544 232445 322445 223455 223545 223554',
'223445 223454 233444 222455 332454 322454 323544 233445 332445',
'323445 233454 233544 232455 332455 323454 323545 233545 343445',
'333445 333454 233554 233455 333455 323555',
'333545 333554 233555'],
'GM-3':[
'454422 544422 445422 554422 444433 444532 455422 544423 545422 454432 544532 545423 555422',
'454423 544432 445423 554432 445433 444533 455432 554423 545432 454532 554532',
'454433 544433 455423 555432 545433 454533 455532 554433 545532',
'455433 545433 555423 555532',
'555433 554533'],
'GM-4':[
'444542 445442 454442 544442 444453 455442 554442 454542 544542 545442 445453 455542 544543',
'444543 445443 454443 544443 444553 555442 554443 554542 545542 545443',
'454543 445543 455443 544453 544553 555443 554453',
'554543 545543 455453 545453',
'554553']}
codes={k:[v.split() for v in cols] for k,cols in codes.items()}

# Cover.
C.setFillColor(ink);C.rect(0,0,612,792,fill=1,stroke=0)
C.setStrokeColor(HexColor('#8196A4'));C.rect(36,36,540,720,fill=0,stroke=1)
C.setFillColor(white);C.setFont('Helvetica-Bold',12);C.drawString(62,704,'GENERAL MOTORS')
C.setFont('Helvetica-Bold',39);C.drawString(62,577,'MASTER KEY SET')
C.setFont('Helvetica',31);C.drawString(62,532,'No. 3067')
C.setStrokeColor(HexColor('#8196A4'));C.line(62,493,550,493)
C.setFont('Helvetica',19);C.drawString(62,450,'How to service General Motors car locks')
C.setFont('Helvetica-Bold',25);C.drawString(62,403,'1935-66')
C.setFont('Helvetica-Bold',13);C.drawString(62,205,'Curtis Industries, Inc.')
C.setFont('Helvetica',12);C.drawString(62,182,'Eastlake, Ohio')
C.setFont('Helvetica-Bold',10);C.drawString(62,111,'RETYPED PARTIAL EDITION')
C.setFont('Helvetica',9);C.drawString(62,91,'Rebuilt from the supplied cover and printed pages 1-3.')
C.drawString(62,76,'Original wording retained; layout reorganized. Missing content is not reconstructed.')
end()

start(2,'The set and its use','Original printed page 1  |  Retypeset partial edition')
para('This key has been designed to open 98% of all Buick, Cadillac, Chevrolet Pass. &amp; Trucks, G.M.C. Trucks, Oldsmobile and Pontiac locks from 1935-66. This set is the result of years of development and has been precision made, inspected and tested to assure complete accuracy and uniformity of manufacture. Care should be taken to assure that these master keys do not fall into the hands of unauthorized personnel.')
para("Included in the set on the following pages is a list of codes to make the exact duplicates of original customer's keys.")
para('Editorial note: The coverage percentage above is a claim in the original manual, not an independently verified modern specification.',small)
heading('How to use the set')
para('The set consists of 64 keys which are arranged in numerical sequence (GM-1 through GM-64) and also in the order of popularity. Key no. GM-1 opens the largest possible number of locks, key no. GM-2 the next largest possible number of locks and so on through key no. GM-64.')
para('To find the master key to open a lock begin with key no. GM-1, insert the key all the way in the lock and turn both left and right. If the key will not open the lock, withdraw it about 1/16&quot; and try again. Do this several times using a slight push pull action each time. If this will not open the lock go to the next key following the same procedure until the master key which will open the lock has been found.')
heading('Contents of this reconstruction')
para('Page 3 - Replacement-key instructions and example<br/>Page 4 - Code tables: GM-1 and GM-2<br/>Page 5 - Code tables: GM-3 and GM-4<br/>Page 6 - Source record and missing material',small)
para('The photograph of the key ring on original page 1 is not reproduced. This edition retains its identifying caption: Set No. 3067.',small)
end()

start(3,'Replacement-key instructions','Original printed page 2  |  Example also checked against printed page 3')
para('Then following the instructions below, proceed to cut a replacement key for the customer\'s lock using your Curtis No. 1 or No. 14 gun-type code cutter with the GM dial position (color code-red) or post and the GM carriage (color code-red). Refer to your Curtis code book for the correct key blank to use.')
para('<b>1-2.</b> Find this master key number on the code sheets. For example, suppose we find that this is master key no. GM-3 as shown on the example key chart (Fig. 1) under key no. GM-3.')
table([col[:3] for col in codes['GM-3']],letters='ABC',compact=True)
para('Figure 1. GM-3 example chart. Column labels and row letters follow the original.',small)
steps=[
('3','Cut keys under GM-3 trying each time to see whether cut key will open the lock. All keys cut under GM-3 can be cut from one key because as you cut, each additional cut is deeper. If the correct key has not been found, in this case after 5 cuts, the key cannot be used and is miscut. Take a new key and go thru the same process under B. Then with another key under C, etc. until the correct key is found.'),
('4','In some few cases one master key will open a lock but after cutting all the keys listed for that master key the correct key has not been cut. It is then necessary to find another master key that will work and cut the keys under that number until the correct key is found.'),
('5','If the trunk and glove compartment key is lost, usually the same key fits both locks; find the right key by using the trunk lock. This lock has more tumblers than the glove compartment lock. Finding the correct key for the trunk will therefore open the glove compartment, but the reverse procedure will not always work.'),
('6','The correct master key can be found quicker by dividing the master set in two and have two people try both door locks until the correct master key has been found.'),
('7','If you do not want to go through the above procedure, you can sell the master key which opens the lock and order a new one from Curtis by the number stamped on the key. When ordering a new master key be sure to use the correct prefix, for example, GM-3, GM-4, etc. You may also make a master key on a key duplicator*. The master key cannot be cut on the Curtis No. 1 or No. 14 gun-type code cutter. The cuts on a master key are 1/2 cuts in between full cuts and the No. 1 and No. 14 code cutters are not designed to take those cuts.')]
instruction=ParagraphStyle('instruction',parent=body,fontSize=9.4,leading=12.4,spaceAfter=6)
for num,txt in steps:para('<b>'+num+'.</b> '+txt,instruction)
para('* Curtis No. 2 Key Duplicator Machine.',small)
end()

for n,names in [(4,['GM-1','GM-2']),(5,['GM-3','GM-4'])]:
    start(n,'Master-key code tables','Original printed page 3  |  Blanks retain the original table layout')
    para('For all GM products 1935-66. Master key numbers with code for cutting exact keys.',small)
    for name in names:
        heading(name);table(codes[name],compact=True)
    if n==5:para('GM-3, row C is crossed by a dark line in the photograph; its five entries are also visible in Figure 1 on original page 2 and were checked there.',small)
    end()

start(6,'Source record and missing pages','Editorial appendix  |  Research date: November 24, 2026')
heading('What was supplied')
para('Three photographs: s-l1600-1.webp (cover), s-l1600-3.webp (printed page 1), and s-l1600-2.webp (printed pages 2 and 3). The text and code tables in this edition were transcribed from those photographs. Paragraph breaks, punctuation and typography were normalized; headings and this appendix are editorial additions. No code values were inferred from a numerical pattern.')
heading('What is missing')
para('The supplied text describes a 64-key set numbered GM-1 through GM-64. Printed page 3 contains tables for GM-1 through GM-4 only. Tables for GM-5 through GM-64 are absent from the supplied material. Their exact original page numbers and the manual\'s total page count have not been verified. Any additional lock-service sections beyond printed page 3 are also unavailable; their contents cannot be established from these photographs.')
heading('Search result')
para('Searches for the exact title, set number, Curtis publisher, and PDF/download variants located sales listings for reproductions, but no verified freely accessible full scan or additional readable pages. No outside pages have been added to this edition.')
para('<b>Reproduction listing:</b> GM Master Keys Tryout Set No. 3067 Instruction Manual Copy 1935-1966, eBay item 265025181011. The listing identifies Curtis as publisher and describes a copy of the original. It does not establish the completeness of any particular copy.',small)
para('<link href="https://www.ebay.com/itm/265025181011" color="#234F72">https://www.ebay.com/itm/265025181011</link>',small)
para('<b>Earlier listing:</b> eBay item 226073090355, Instruction Manual (Copy) for #3067 Chevy GM Master Keys Tryout Set 1935-66. The search result reports it as out of stock.',small)
para('<link href="https://www.ebay.com/itm/226073090355" color="#234F72">https://www.ebay.com/itm/226073090355</link>',small)
heading('How to complete the record')
para('A complete scan or reproduction is needed to supply the remaining tables and establish the final page count. Before purchasing, ask the seller to confirm that the copy includes GM-1 through GM-64 and all lock-service pages. Listings and availability can change.',small)
end()
C.save()
print(OUT)
