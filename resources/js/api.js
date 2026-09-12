import axios from './bootstrap';

export async function ensureCsrf() {
    await axios.get('/sanctum/csrf-cookie');
}

export async function login(email, password) {
    await ensureCsrf();
    const { data } = await axios.post('/api/login', { email, password });
    return data.user;
}

export async function logout() {
    await ensureCsrf();
    await axios.post('/api/logout');
}

export async function fetchMe() {
    const { data } = await axios.get('/api/me');
    return data.user;
}

export async function fetchOrganization() {
    const { data } = await axios.get('/api/organization');
    return data;
}

export async function saveOrganization(yandexUrl, reviewCap = null) {
    await ensureCsrf();
    const { data } = await axios.post('/api/organization', {
        yandex_url: yandexUrl,
        review_cap: reviewCap,
    });
    return data;
}

export async function reparseOrganization() {
    await ensureCsrf();
    const { data } = await axios.post('/api/organization/reparse');
    return data;
}

export async function fetchReviews(page = 1) {
    const { data } = await axios.get('/api/organization/reviews', { params: { page } });
    return data;
}

export async function fetchSnapshots(page = 1) {
    const { data } = await axios.get('/api/organization/snapshots', {
        params: { page },
    });
    return data;
}

export async function fetchSnapshot(snapshotId) {
    const { data } = await axios.get(`/api/organization/snapshots/${snapshotId}`);
    return data;
}

export async function fetchSnapshotReviews(snapshotId, page = 1) {
    const { data } = await axios.get(`/api/organization/snapshots/${snapshotId}/reviews`, {
        params: { page },
    });
    return data;
}
