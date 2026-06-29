import defaultTextThemes from './../textThemes';

class TextStylingService {
    
    constructor() {
        
    }

    getDefaultTextStylingSettings() {
        return defaultTextThemes;
    }

    getTextStylingSettingsObject(textStylingSettings) {
        const themes = ['light', 'dark', 'eink']
        const levels = [
            'Ignored word',
            'New word',
            'Known word',
            'Level 1 word',
            'Level 2 word',
            'Level 3 word',
            'Level 4 word',
            'Level 5 word',
            'Level 6 word',
            'Level 7 word',
            'Level 8 word',
            'Level 9 word',
            'Level 10 word',
            'Known phrase',
            'Level 1 phrase',
            'Level 2 phrase',
            'Level 3 phrase',
            'Level 4 phrase',
            'Level 5 phrase',
            'Level 6 phrase',
            'Level 7 phrase',
        ]

        let settings = {}
        themes.forEach((theme) => {
            settings[theme] = {}
            levels.forEach((level) => {
                Object.assign(settings[theme], this.getCssSettingObject(textStylingSettings, theme, level))
            })
        })

        return settings
    }

    // returns an object with css styling for a single theme/word level combination
    getCssSettingObject(textStylingSettings, theme, level) {
        // Resolve level settings; fall back to Level 7 for undefined higher levels (8-10)
        let resolvedLevel = level;
        if (textStylingSettings[theme] && textStylingSettings[theme][level] === undefined) {
            const lvlMatch = level.match(/^Level (\d+) word$/);
            if (lvlMatch && parseInt(lvlMatch[1], 10) >= 8) {
                resolvedLevel = 'Level 7 word';
            }
        }
        const settings = textStylingSettings[theme][resolvedLevel];

        const levelMapping = {
            'Level 1 word': 'wordLevel-1',
            'Level 2 word': 'wordLevel-2',
            'Level 3 word': 'wordLevel-3',
            'Level 4 word': 'wordLevel-4',
            'Level 5 word': 'wordLevel-5',
            'Level 6 word': 'wordLevel-6',
            'Level 7 word': 'wordLevel-7',
            'Level 8 word': 'wordLevel-8',
            'Level 9 word': 'wordLevel-9',
            'Level 10 word': 'wordLevel-10',
            'Known word': 'wordLevel0',
            'Ignored word': 'wordLevel1',
            'New word': 'wordLevel2',
            'Selected word': 'wordLevelSelected',
            'Known phrase': 'phraseLevel0',
            'Level 1 phrase': 'phraseLevel-1',
            'Level 2 phrase': 'phraseLevel-2',
            'Level 3 phrase': 'phraseLevel-3',
            'Level 4 phrase': 'phraseLevel-4',
            'Level 5 phrase': 'phraseLevel-5',
            'Level 6 phrase': 'phraseLevel-6',
            'Level 7 phrase': 'phraseLevel-7',
            'Selected pharse': 'phraseLevelSelected',
        }

        const mappedKey = levelMapping[level];
        let cssVariables = {}
        
        cssVariables[`--interactive-text-${mappedKey}-color`] = settings.textColor;
        cssVariables[`--interactive-text-${mappedKey}-border-color`] = settings.borderColor;
        cssVariables[`--interactive-text-${mappedKey}-border-style`] = settings.borderStyle;
        cssVariables[`--interactive-text-${mappedKey}-border-radius`] = settings.borderRadius + 'px';
        
        // padding
        cssVariables[`--interactive-text-${mappedKey}-padding-top`] = settings.paddingTop + 'px';
        cssVariables[`--interactive-text-${mappedKey}-padding-bottom`] = settings.paddingBottom + 'px';
        
        // horizontal padding for spaceless languages only
        if (settings.horizontalPaddingSpacelessLanguagesOnly) {
            cssVariables[`--interactive-text-${mappedKey}-padding-left`] = '0px';
            cssVariables[`--interactive-text-${mappedKey}-padding-right`] = '0px';
            cssVariables[`--interactive-text-${mappedKey}-spaceless-language-padding-left`] = settings.paddingHorizontal + 'px';
            cssVariables[`--interactive-text-${mappedKey}-spaceless-language-padding-right`] = settings.paddingHorizontal + 'px';
        } else {
            cssVariables[`--interactive-text-${mappedKey}-padding-left`] = settings.paddingHorizontal + 'px';
            cssVariables[`--interactive-text-${mappedKey}-padding-right`] = settings.paddingHorizontal + 'px';
            cssVariables[`--interactive-text-${mappedKey}-spaceless-language-padding-left`] = settings.paddingHorizontal + 'px';
            cssVariables[`--interactive-text-${mappedKey}-spaceless-language-padding-right`] = settings.paddingHorizontal + 'px';
        }
        
        // add colors 
        cssVariables[`--interactive-text-${mappedKey}-background-transparency`] = settings.backgroundTransparency + '%';
        cssVariables[`--interactive-text-${mappedKey}-border-color`] = settings.borderColor;
        cssVariables[`--interactive-text-${mappedKey}-background-color`] = settings.backgroundColor;
        cssVariables[`--interactive-text-${mappedKey}-color`] = settings.textColor;

        // set top border
        if (!settings.borderTop || !settings.borderWidth) {
            cssVariables[`--interactive-text-${mappedKey}-border-top-width`] = '0px'
        } else {
            cssVariables[`--interactive-text-${mappedKey}-border-top-width`] = settings.borderWidth + 'px';
        }

        // set bottom border
        if (!settings.borderBottom || !settings.borderWidth) {
            cssVariables[`--interactive-text-${mappedKey}-border-bottom-width`] = '0px'
        } else {
            cssVariables[`--interactive-text-${mappedKey}-border-bottom-width`] = settings.borderWidth + 'px';
        }

        // set side borders
        if (!settings.borderSides || !settings.borderWidth) {
            cssVariables[`--interactive-text-${mappedKey}-border-left-width`] = '0px'
            cssVariables[`--interactive-text-${mappedKey}-border-right-width`] = '0px'
        } else {
            cssVariables[`--interactive-text-${mappedKey}-border-left-width`] = settings.borderWidth + 'px';
            cssVariables[`--interactive-text-${mappedKey}-border-right-width`] = settings.borderWidth + 'px';
        }

        // add bold styling
        if (settings.bold) {
            cssVariables[`--interactive-text-${mappedKey}-weight`] = 'bold'
        } else {
            cssVariables[`--interactive-text-${mappedKey}-weight`] = 'normal'
        }

        // add italic styling
        if (settings.italic) {
            cssVariables[`--interactive-text-${mappedKey}-style`] = 'italic'
        } else {
            cssVariables[`--interactive-text-${mappedKey}-style`] = 'normal'
        }

        // add wavy underline
        if (settings.wavyUnderline) {
            cssVariables[`--interactive-text-${mappedKey}-text-decoration`] = 'underline'
            cssVariables[`--interactive-text-${mappedKey}-wave-width`] = settings.borderWidth + 'px'
            cssVariables[`--interactive-text-${mappedKey}-border-bottom-width`] = '0px'
            cssVariables[`--interactive-text-${mappedKey}-border-top-width`] = '0px'
            cssVariables[`--interactive-text-${mappedKey}-border-left-width`] = '0px'
            cssVariables[`--interactive-text-${mappedKey}-border-right-width`] = '0px'
        } else {
            cssVariables[`--interactive-text-${mappedKey}-text-decoration`] = 'none'
        }

        return cssVariables
    }
}

export default new TextStylingService();
