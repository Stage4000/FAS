"""Tests the exact candidate SQL in synthetic SQLite, not PHP/PDO execution."""
import json, pathlib, re, sqlite3, tempfile, threading
source = pathlib.Path(__file__).resolve().parent / 'fixtures/image-auto-pilot/ImagePilot.php'
text = source.read_text()
sql = re.search(r"\$sql=<<<'SQL'\n(.*?)\nSQL;", text, re.S).group(1)
checks = 0

def check(value, label):
    global checks
    if not value:
        raise AssertionError(label)
    checks += 1

with tempfile.TemporaryDirectory(prefix='fas-synthetic-snapshot-') as root:
    dbpath = str(pathlib.Path(root) / 'catalog.sqlite')
    reader = sqlite3.connect(dbpath)
    writer = sqlite3.connect(dbpath)
    reader.row_factory = sqlite3.Row
    reader.execute('PRAGMA journal_mode=WAL')
    reader.execute('CREATE TABLE products(id INTEGER PRIMARY KEY,is_active INTEGER,show_on_website INTEGER,image_url,images)')
    reader.execute('INSERT INTO products VALUES(1,1,1,?,?)', ('https://i.ebayimg.com/a.png', '[]'))
    reader.commit()

    def update(url, images):
        writer.execute('UPDATE products SET image_url=?,images=? WHERE id=1', (url, images))
        writer.commit()

    def query():
        return dict(reader.execute(sql, ('1',)).fetchone())

    small = reader.execute('SELECT length(CAST(images AS BLOB)) FROM products WHERE id=1').fetchone()[0]
    update('https://i.ebayimg.com/a.png', 'x' * 131073)
    old_data = reader.execute('SELECT images FROM products WHERE id=1').fetchone()[0]
    check(small <= 131072 and len(old_data.encode()) > 131072, 'reproduce two-query race')
    row = query()
    check(row['snapshot_valid'] == 0 and row['images'] is None, 'oversized competing replacement is not fetched')

    for url, imgs, expected, label in [
        ('u' * 2048, 'x' * 131072, 1, 'inclusive bounds'),
        ('u' * 2049, '[]', 0, 'url byte bound'),
        ('é' * 1025, '[]', 0, 'multibyte URL byte bound'),
        ('https://i.ebayimg.com/a', 'é' * 65537, 0, 'multibyte image JSON bound'),
        (None, None, 1, 'null fields'),
        (b'url', '[]', 0, 'blob URL rejected'),
        ('url', b'[]', 0, 'blob images rejected'),
        (123, '[]', 0, 'integer URL rejected'),
        ('url', 123, 0, 'integer images rejected'),
        ('url', 1.5, 0, 'real images rejected'),
    ]:
        update(url, imgs)
        row = query()
        check(row['snapshot_valid'] == expected, label)
        if not expected:
            check(row['image_url'] is None or row['images'] is None, label + ' invalid payload projected out')
    check(reader.execute(sql, ('2',)).fetchone() is None, 'missing product')

    # Hold a cursor between two fetches, then modify via another connection.
    # SQLite statement snapshot stays coherent regardless of later commits.
    update('https://i.ebayimg.com/a', '["a"]')
    reader.execute('INSERT INTO products VALUES(2,1,1,?,?)', ('https://i.ebayimg.com/a', '["a"]'))
    reader.commit()
    scan_sql = sql.replace('WHERE id=?', 'WHERE id IN (?,?) ORDER BY id')
    cursor = reader.execute(scan_sql, (1, 2))
    first = dict(cursor.fetchone())
    writer.execute('UPDATE products SET image_url=?,images=?', ('https://i.ebayimg.com/b', '["b"]'))
    writer.commit()
    second = dict(cursor.fetchone())
    check(first['image_url'].endswith('/a') and second['image_url'].endswith('/a'), 'statement snapshot survives competing commit')
    check(query()['image_url'].endswith('/b'), 'next statement sees committed replacement')
    cursor.close()

    # Concurrent alternating coherent tuples must never create a mixed row.
    errors = []
    def modify():
        w = sqlite3.connect(dbpath)
        try:
            for i in range(100):
                marker = str(i)
                w.execute('UPDATE products SET image_url=?,images=? WHERE id=1', ('https://i.ebayimg.com/' + marker, marker))
                w.commit()
        except Exception as exc:
            errors.append(str(exc))
        finally:
            w.close()
    update('https://i.ebayimg.com/initial', 'initial')
    thread = threading.Thread(target=modify)
    thread.start()
    for _ in range(200):
        row = query()
        check(row['snapshot_valid'] == 1 and row['image_url'].rsplit('/', 1)[1] == row['images'], 'coherent concurrent tuple')
    thread.join()
    check(not errors, 'competing writer completed')
    reader.close()
    writer.close()
print(json.dumps({'status': 'PASS', 'checks': checks, 'sqlite': sqlite3.sqlite_version,
                  'exact_candidate_sql': True, 'php_pdo_executed': False, 'production_data': False}))
