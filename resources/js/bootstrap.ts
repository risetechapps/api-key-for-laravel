import axios from "axios";

const axiosDefault = axios;

axiosDefault.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axiosDefault.defaults.headers.common['Accept-Language'] = 'pt-BR,pt;q=0.9';

export default axiosDefault;
