export const updateMarker = (client, reviewCardId, marker) => client.patch(
    `/review-cards/${reviewCardId}/marker`,
    { marker },
);

export const bulkUpdateMarkers = (client, ids, marker) => client.patch(
    '/review-cards/manage/markers',
    { ids, marker },
);
