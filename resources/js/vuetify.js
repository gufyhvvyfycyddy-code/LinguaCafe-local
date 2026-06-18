import Vue from 'vue'
import Vuetify from 'vuetify'
import themes from './themes'
import '@mdi/font/css/materialdesignicons.css'
import zhHans from 'vuetify/lib/locale/zh-Hans'

Vue.use(Vuetify);

export default new Vuetify({
    icons: {
        defaultSet: 'mdi'
    },
    theme: {    
        options: { 
            customProperties: true,
            variations: true
        },
    },
    lang: {
        locales: { zhHans },
        current: 'zhHans',
    },
})
