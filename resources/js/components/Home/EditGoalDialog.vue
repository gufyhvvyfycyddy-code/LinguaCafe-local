<template>
    <v-dialog v-model="value" persistent max-width="500px" height="300px">
        <v-card class="rounded-lg">
            <v-card-title>
                <span class="text-h5">编辑每日{{ goalName }}目标</span>
                <v-spacer></v-spacer>
                <v-btn icon @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
                
            <v-card-text>
                <v-alert
                    class="rounded-lg"
                    color="primary"
                    type="info"
                    border="left"
                    dark
                >
                    这个设置只影响今天和之后的目标，不会修改过去日期的目标。
                </v-alert>

                <label class="font-weight-bold">目标数量</label>
                <v-text-field
                    v-model="goalQuantity"
                    class="mb-1"
                    type="number"
                    hide-details
                    filled
                    dense
                    rounded
                    placeholder="目标数量"
                    @change="quantityChanged"
                />
            </v-card-text>

            <v-card-actions>
                <v-spacer></v-spacer>

                <v-btn rounded text @click="close">取消</v-btn>
                <v-btn 
                    rounded 
                    depressed
                    color="primary" 
                    @click="save"
                    :disabled="saving"
                    :loading="saving"
                >
                    保存
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    export default {
        props: {
            value : Boolean,
            _id: Number,
            _name: String,
            _goalQuantity: Number,
            _achievedQuantity: Number,
        },
        computed: {
            goalName() {
                const names = {
                    Reviews: '复习',
                    Reading: '阅读',
                    'New words': '新词',
                };
                return names[this.$props._name] || this.$props._name;
            }
        },
        emits: ['input'],
        data: function() {
            return {
                saving: false,
                goalQuantity: this.$props._goalQuantity
            };
        },
        mounted: function() {
        },
        methods: {
            quantityChanged() {
                if (this.goalQuantity == '' || this.goalQuantity < 0) {
                    this.goalQuantity = 0;
                }
            },
            save() {
                this.saving = true;

                axios.post('/goal/update', {
                    goalId: this.$props._id,
                    newGoalQuantity: this.goalQuantity
                }).then(() => {
                    this.$emit('save');
                    this.close();
                }).catch(() => {
                }).finally(() => {
                    this.saving = false;
                });
            },
            close() {
                this.$emit('input', false);
            }
        }
    }
</script>
