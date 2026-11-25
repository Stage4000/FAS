from pathlib import Path
base=Path('build_manual.py').read_text(encoding='utf-8').split("start(6,'Source record")[0]
base=base.replace('GM-3067-Retypeset-Partial-Manual.pdf','GM-3067-Retypeset-Partial-Manual-v2.pdf')
base=base.replace('Rebuilt from the supplied cover and printed pages 1-3.','Rebuilt from the supplied cover, contents, and printed pages 1-3 and 9.')
base=base.replace('Page 6 - Source record and missing material','Page 6 - Original contents; pages 7-9 - GM-31 through GM-37<br/>Page 10 - Cropped fragment; page 11 - Source record')
exec(base)
start(6,'Original contents','Newly supplied unnumbered contents  |  References below are original page numbers')
heading('Curtis Industries, Inc.')
for title,pages in [('Introduction','1'),('How to Use the Codes','1, 2'),('Codes','3-13'),('Curtis Door Opener Tools','14-17')]:
    para('<b>'+title+'</b> ........................................ '+pages)
para('No. 5202 - 5203<br/>No. 204<br/>No. 5205<br/>No. 5206<br/>No. 12019')
heading('Reading this edition')
para('The entries above reproduce the newly supplied contents page. They refer to original printed pages, not this PDF\'s page numbers. Original page references appear in each section footer. This is still an incomplete edition.')
para('New material follows: original page 9 (GM-31 through GM-37), a record of the cropped facing-page fragment, and an updated source record. Missing codes are not generated or extrapolated.',small)
end()
newcodes={
'GM-31':['422445 423444 432444 423544 422455 423445 423454','432445 433444 532454 433544 423455 423545 433454','433445 533444 533454 533544','433545 533445 533554','533545 533455'],
'GM-32':['424232 434222 424322 434223 424233 424323 424332','434232 534222 435322 534223 434233 424333 534332','534232 534322 435323 534323','534233 535322 435333','534333 535332'],
'GM-33':['224424 224434 234424 224435 235424 224534 224535','324424 324434 234434 234435 235434 324534','334424 324435 234534 234535 235534','334434 324535 334534','334435'],
'GM-34':['244234 244324 244235 244334 354224 344324 345324','344234 245324 344235 244335 354324 344334','354234 245334 354235 344335 355324','354334 245335','355334 345335'],
'GM-35':['422342 423242 423243 423342 432343 532242 422353 532243','432342 433242 433243 423343 432353 533342','532342 533242 433343 423353','532343 533243','532353 533343'],
'GM-36':['422443 423442 432442 423443 423542 433542','422453 433442 532443 433443 423543 533542','432453 533442 532453 433543 423553','433453 533443 533453','433553 533543'],
'GM-37':['224223 224322 234222 234223 234332 324232 224333 324323','224233 224323 234232 334223 235332 324332','234233 234323 334232 334323','234333 235323 334332','']}
newcodes={k:[v.split() for v in cols] for k,cols in newcodes.items()}
for n,names in [(7,['GM-31','GM-32','GM-33']),(8,['GM-34','GM-35','GM-36']),(9,['GM-37'])]:
    start(n,'Master-key code tables','Original printed page 9  |  Transcribed from s-l1600.webp')
    for name in names:heading(name);table(newcodes[name],compact=True)
    if n==9:
        heading('Page 9 transcription notes')
        para('All seven group labels GM-31 through GM-37 and their row letters are visible on the right-hand page. Blank cells reproduce blank positions in the source. The cropped facing page is recorded separately; its missing group identifiers have not been assigned from assumed numerical order.')
    end()
start(10,'Cropped facing-page fragment','Left edge of s-l1600.webp  |  Group identifiers and first columns are outside the frame')
para('The page facing original page 9 is substantially outside the photograph. It is likely page 8, based on its facing position, but its page number is not visible. Missing identifiers, row letters and codes cannot be recovered reliably from this image. The following is a text record of selected readable fragments, not a reconstructed working chart. Entries are listed top-to-bottom within each visible column; no missing values or row assignments are inferred.',small)
fragments=[
('Top block','344443 344542 344543 244553 245543 354553','354443 354542 245553 245543','354543 354553 344553','[uncertain]'),
('Second block','334432 234433 224533 334433 324323','334532 334543 324533','335532','[none readable]'),
('Third block','343344 353244 233345 342355 353245','343354 353344 343345','343355 353354','[none readable]'),
('Fourth block','534423 534432 435433 434533 435532 535423','534433 534532 435533','534533','[none readable]'),
('Fifth block','[partial column]','434555 534554 535455','534555','[none readable]'),
('Bottom block','[outside frame]','443353 553342 543343','453353 553343','[none readable]')]
for title,c2,c3,c4,c5 in fragments:
    para('<b>'+title+'</b>',body)
    para('2nd: '+c2+'<br/>3rd: '+c3+'<br/>4th: '+c4+'<br/>5th: '+c5,small)
end()
start(11,'Source record and missing pages','Editorial appendix  |  Updated November 24, 2026')
heading('Sources and transcription')
para('Five supplied photographs: s-l1600-1.webp (cover), s-l1600-3.webp (page 1), s-l1600-2.webp (pages 2-3), s-l1600 (1).webp (contents), and s-l1600.webp (page 9 and cropped facing page). Text and tables were transcribed; paragraph breaks and typography were normalized. Headings and notes are editorial additions. No missing code values were extrapolated.',small)
heading('Missing material')
para('The contents confirms codes on original pages 3-13 and door-opener tools on pages 14-17. Complete identified tables now available: GM-1 through GM-4 and GM-31 through GM-37. Original pages 4-8 and 10-17 remain unavailable in full. The cropped page facing page 9 is recorded only as fragments. The contents establishes numbered material through page 17; any further back matter is unverified.',small)
heading('OhioLINK / WRHS archive lead')
para('The OhioLINK view rejected direct access, but the XML finding aid was retrieved from WRHS. Its identifier OCLWHi0795 matches the user\'s link. It describes Curtis Industries, Inc. Records, MS 5120, 1944-1975. The finding aid lists no access restrictions; material is held in closed stacks.',small)
para('<b>Relevant inventory:</b> Box 1, Folder 10: Manuals (1959, 1961). Also Box 1, Folder 4: Catalogues; Folder 5: Product Sheets; and Folder 11: Product sheets and advertisements (all undated). The 1959/1961 dates do not establish that the 1935-66 No. 3067 manual is present. Ask staff to identify the titles and quote a complete digital reproduction if the correct manual is held. Supply its cover and contents photographs. Confirm current fees before ordering.',small)
para('<link href="https://catalog.wrhs.org/collections/data/ead/MS5120.xml" color="#234F72">Direct XML finding aid (inventory, not document scans)</link>',small)
para('<link href="https://www.wrhs.org/files/assets/lrs2.pdf" color="#234F72">WRHS Library Research Service information</link><br/><link href="https://wrhs.saas.dgicloud.com/" color="#234F72">WRHS digital collections</link>',small)
heading('Reproduction leads')
para('An earlier search found an eBay reproduction listing (item 265025181011), but no verified complete free scan. Ask the seller to confirm original pages 1-17, all GM-1 through GM-64 tables, and the contents page before buying. No externally sourced manual pages are included in this PDF.',small)
para('<link href="https://www.ebay.com/itm/265025181011" color="#234F72">https://www.ebay.com/itm/265025181011</link>',small)
end()
C.save()
print(OUT)
