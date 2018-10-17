<?php
namespace app\modules\post\models;
use app\modules\post\models\base\PostTag;
use common\models\Tag;
use meysampg\intldate\IntlDateTrait;
use Yii;
use developit\slug\PesianSluggableBehavior;

class Post extends \app\modules\post\models\base\Post
{
    use IntlDateTrait;
    /**
     * @inheritdoc
     */
    public $hour;
    public $minute;
    public $date;
    public $comment_count;
    public $more;
    public $tags;
    public $post_id;
    public $auto_save = true;

    public $count;
    public $sum;

    public function behaviors()
    {
        return [
            [
                'class' => PesianSluggableBehavior::className(),
                'attribute' => 'title',
            ],
        ];
    }

    public function rules()
    {
        $parentRules = parent::rules();

        $parentRules[] = [['title'], 'trim'];
        $parentRules[] = ['title', 'filter','filter' => function($value){
            return preg_replace('/\s+/',' ',str_replace(['/','\\'],' ',$value));
        }];
        $parentRules[] = [['pin_post', 'enable_comments'], 'boolean'];

        $parentRules[] = [['hour'], 'integer', 'min' => 0, 'max' => 23];
        $parentRules[] = [['minute'], 'integer', 'min' => 0, 'max' => 59];

        $parentRules[] = [['date'], 'date', 'format' => 'php:Y-m-d'];
        $parentRules[] = [['date'], 'dateValidate', 'skipOnEmpty' => true];

        return $parentRules;
    }

    public function dateValidate()
    {
//        //
//        $this->setOriginTimeZone('America/Los_Angeles');
        if($this->status == 2)
        {
            $currentTime = new \DateTime;

            if (Yii::$app->language == 'fa-IR')
            {
                $date = explode('-', $this->date);
                $newCreatedAt = new \DateTime($this->fromPersian([$date[0], $date[1], $date[2], $this->hour, $this->minute, 0], 'fa')->toGregorian()->setFinalTimeZone(Yii::$app->timeZone)->asDateTime());
            } else
            {
                $newCreatedAt = new \DateTime("{$this->date} {$this->hour}:{$this->minute}");
            }

//            if ($currentTime >= $newCreatedAt)
//            {
//                $this->addError('date', Yii::t('app', 'Send In Future Date Not Valid'));
//            } else
            {
                $this->created_at = $newCreatedAt->format('Y-m-d H:i:s');
            }
        }
    }
    
    public function beforeSave($insert)
    {
//        $this->getOriginTimeZone();
//        var_dump($this->getOriginTimeZone()); exit();
        if($insert)
        {
            $this->created_by = Yii::$app->user->id;
        }

        if(!$insert)
        {
            $this->updated_by = Yii::$app->user->id;
            $this->updated_at = (new \DateTime())->format('Y-m-d H:i:s');
        }
        return parent::beforeSave($insert); // TODO: Change the autogenerated stub
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        $parentAttributeLabels = parent::attributeLabels();
        $parentAttributeLabels['auto_save'] = Yii::t('app', 'Auto save as draft');
        $parentAttributeLabels['date'] = Yii::t('app', 'Date');
        $parentAttributeLabels['hour'] = Yii::t('app', 'Hour');
        $parentAttributeLabels['minute'] = Yii::t('app', 'Minute');
        return $parentAttributeLabels;
    }

    public function afterSave($insert,$changedAttributes)
    {
        // insert Categories
        $selectedCategories = Yii::$app->request->post('post_categories');
        if($selectedCategories !== null)
        {
            $data = [];
            foreach((array)$selectedCategories as $categoryId)
            {
                if($categoryId != '' && Category::findOne($categoryId) === null)
                {
                    $categoryModel = new Category;
                    $categoryModel->title = $categoryId;
                    $categoryModel->save();
                    $categoryId = $categoryModel->id;
                }

                if($categoryId != '')
                {
                    $data[] = [$this->id, $categoryId];
                }
            }

            if(!empty($data))
            {
                PostCategory::deleteAll(['post_id' => $this->id]);
                Yii::$app->db->createCommand()->batchInsert(PostCategory::tableName(), ['post_id', 'category_id'], $data)->execute();
            }
        }

        // insert tags
        $tags = Yii::$app->request->post('tags');
        if(!empty($tags))
        {
            $data = [];
            foreach((array)$tags as $t)
            {
                $tagId = null;
                if($t != '')
                {
                    $exists = Tag::findOne(['title' => $t]);
                    if($exists !== null)
                    {
                        $tagId = $exists->id;
                    }
                    else
                    {
                        $tagModel = new Tag;
                        $tagModel->title = $t;
                        $tagModel->save();
                        $tagId = $tagModel->id;
                    }
                }

                if($tagId !== null)
                {
                    $data[] = [$this->id, $tagId];
                }

            }

            if(!empty($data))
            {
                PostTag::deleteAll(['post_id' => $this->id]);
                Yii::$app->db->createCommand()->batchInsert(PostTag::tableName(), ['post_id', 'tag_id'], $data)->execute();
            }
        }
    }

    public function getSelectedCategoriesTitle($resultType = 'string')
    {
        $query = new \yii\db\Query;
        $categories = $query->select("c.id,c.title")->from(['pc' => PostCategory::tableName()])->leftJoin(['c' => Category::tableName()], 'pc.category_id = c.id')->where(['pc.post_id' => $this->id])->all();

        if($resultType == 'array')
        {
            return $categories;
        }

        $selectedCategories = \yii\helpers\ArrayHelper::getColumn($categories,function($element){
            return $element['title'];
        });
        return implode(', ',$selectedCategories);
    }

    public static function convertToAssociativeArray($value)
    {
        $value = explode(',', $value);
        $newValue = [];
        foreach ((array)$value as $v)
        {
            if(!empty($v))
            {
                $newValue[$v] = $v;
            }
        }
        return $newValue;
    }

    public function getSelectedTags()
    {
        $result = $this->hasMany(PostTag::className(), ['post_id' => 'id'])
            ->select('t.*')
            ->alias('pt')
            ->innerJoin(['t' => Tag::tableName()], 'pt.tag_id = t.id')
            ->all();

        return \yii\helpers\ArrayHelper::map($result,'title','title');
    }

    public function postStatus()
    {
        return [
            '0' => Yii::t('app','Draft'),
            '1' => Yii::t('app','Publish'),
            '2' => Yii::t('app','Send in future'),
        ];
    }

}
