import assert from 'node:assert/strict';
const { mediaSlotKey, selectMedia } = await import('../../resources/js/components/Review/MediaSlot.js');

assert.equal(
    await mediaSlotKey('word_pronunciation'),
    '98c1eb4ee93476743763878fcb96a25fbc9a175074d64004779ecb5242f645e6',
);
const exampleKey = await mediaSlotKey('example_audio', '  A focused example.  ');
const items = [
    { role: 'example_audio', slot_key: 'other', source_text: 'Other.', asset_id: 'wrong' },
    { role: 'example_audio', slot_key: exampleKey, source_text: 'A focused example.', asset_id: 'right' },
];
assert.equal(selectMedia(items, 'example_audio', exampleKey).asset_id, 'right');
assert.equal(selectMedia(items, 'example_audio', null, ' A focused example. ').asset_id, 'right');
assert.equal(selectMedia(items, 'word_pronunciation'), null);
console.log('M18 media slot tests passed');
