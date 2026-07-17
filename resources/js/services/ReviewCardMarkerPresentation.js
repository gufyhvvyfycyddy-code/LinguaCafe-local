export const markerChoices = [
    { value: 0, label: '未标记', color: 'grey' },
    { value: 1, label: '红色', color: 'red' },
    { value: 2, label: '橙色', color: 'orange' },
    { value: 3, label: '黄色', color: 'amber' },
    { value: 4, label: '绿色', color: 'green' },
    { value: 5, label: '蓝色', color: 'blue' },
    { value: 6, label: '紫色', color: 'purple' },
    { value: 7, label: '粉色', color: 'pink' },
];

export const markerChoice = value => markerChoices.find(choice => choice.value === Number(value)) || markerChoices[0];
