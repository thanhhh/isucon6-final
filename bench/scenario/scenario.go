package scenario

import (
	"errors"
	"io"
	"strconv"

	"encoding/json"

	"net/http"

	"fmt"
	"os"

	"net/url"

	"github.com/PuerkitoBio/goquery"
	"github.com/catatsuy/isucon6-final/bench/fails"
	"github.com/catatsuy/isucon6-final/bench/score"
	"github.com/catatsuy/isucon6-final/bench/session"
)

var (
	IndexGetScore   int64 = 2
	SVGGetScore     int64 = 1
	RoomCreateScore int64 = 20
)

func makeDocument(r io.Reader) (*goquery.Document, error) {
	doc, err := goquery.NewDocumentFromReader(r)
	if err != nil {
		return nil, errors.New(fails.Add("ページのHTMLがパースできませんでした"))
	}
	return doc, nil
}

func extractImages(doc *goquery.Document) []string {
	imageUrls := []string{}

	doc.Find("img").Each(func(_ int, selection *goquery.Selection) {
		if url, ok := selection.Attr("src"); ok {
			imageUrls = append(imageUrls, url)
		}
	})

	return imageUrls
}

func extractCsrfToken(doc *goquery.Document) string {
	var token string

	doc.Find("html").Each(func(_ int, selection *goquery.Selection) {
		if t, ok := selection.Attr("data-csrf-token"); ok {
			token = t
		}
	})

	return token
}

func loadImages(s *session.Session, images []string) error {
	var lastErr error
	for _, image := range images {
		err := s.Get(image, func(status int, body io.Reader) error {
			if status != 200 {
				return errors.New(fails.Add("GET /, ステータスが200ではありません: " + strconv.Itoa(status)))
			}
			score.Increment(SVGGetScore)
			return nil
		})
		if err != nil {
			lastErr = err
		}
	}
	return lastErr

	// TODO: 画像を並列リクエストするようにしてみたが、 connection reset by peer というエラーが出るので直列に戻した
	// もしかすると s.Transport.MaxIdleConnsPerHost ずつ処理するといけるのかも
	//errs := make(chan error, len(images))
	//for _, image := range images {
	//	go func(image string) {
	//		err := s.Get(image, func(status int, body io.Reader) error {
	//			if status != 200 {
	//				return errors.New(fails.Add("GET /, ステータスが200ではありません: " + strconv.Itoa(status)))
	//			}
	//			score.Increment(SVGGetScore)
	//			return nil
	//		})
	//		errs <- err
	//	}(image)
	//}
	//var lastErr error
	//for i := 0; i < len(images); i++ {
	//	err := <-errs
	//	if err != nil {
	//		lastErr = err
	//	}
	//}
	//return lastErr
}

// トップページと画像に負荷をかける
func LoadIndexPage(s *session.Session) {
	var token string
	var images []string

	err := s.Get("/", func(status int, body io.Reader) error {
		if status != 200 {
			return errors.New(fails.Add("GET /, ステータスが200ではありません: " + strconv.Itoa(status)))
		}
		doc, err := makeDocument(body)
		if err != nil {
			return err
		}

		token = extractCsrfToken(doc)

		if token == "" {
			return errors.New(fails.Add("GET /, csrf_tokenが取得できませんでした"))
		}

		images = extractImages(doc)
		if len(images) < 100 {
			return errors.New(fails.Add("GET /, 画像の枚数が少なすぎます"))
		}

		score.Increment(IndexGetScore)

		return nil
	})
	if err != nil {
		return
	}

	err = loadImages(s, images)
	if err != nil {
		return
	}
}

// ページ内のCSRFトークンが毎回変わっていることをチェック
func CheckCSRFTokenRefreshed(s *session.Session) {
	var token string

	err := s.Get("/", func(status int, body io.Reader) error {
		if status != 200 {
			return errors.New(fails.Add("GET /, ステータスが200ではありません: " + strconv.Itoa(status)))
		}
		doc, err := makeDocument(body)
		if err != nil {
			return err
		}

		token = extractCsrfToken(doc)

		if token == "" {
			return errors.New(fails.Add("GET /, csrf_tokenが取得できませんでした"))
		}

		score.Increment(IndexGetScore)

		return nil
	})
	if err != nil {
		return
	}

	_ = s.Get("/", func(status int, body io.Reader) error {
		if status != 200 {
			return errors.New(fails.Add("GET /, ステータスが200ではありません: " + strconv.Itoa(status)))
		}
		doc, err := makeDocument(body)
		if err != nil {
			return err
		}

		t := extractCsrfToken(doc)

		if t == token {
			return errors.New(fails.Add("GET /, csrf_tokenが使いまわされています"))
		}

		score.Increment(IndexGetScore)

		return nil
	})
}

// 一人がroomを作る→大勢がそのroomをwatchする
func MatsuriRoom(s *session.Session, aud string) {
	var token string

	err := s.Get("/", func(status int, body io.Reader) error {
		if status != 200 {
			return errors.New(fails.Add("GET /, ステータスが200ではありません: " + strconv.Itoa(status)))
		}
		doc, err := makeDocument(body)
		if err != nil {
			return err
		}

		token = extractCsrfToken(doc)

		if token == "" {
			return errors.New(fails.Add("GET /, csrf_tokenが取得できませんでした"))
		}

		score.Increment(IndexGetScore)

		return nil
	})
	if err != nil {
		return
	}

	postBody, _ := json.Marshal(struct {
		Name         string `json:"name"`
		CanvasWidth  int    `json:"canvas_width"`
		CanvasHeight int    `json:"canvas_height"`
	}{
		Name:         "ひたすら椅子を描く部屋",
		CanvasWidth:  1024,
		CanvasHeight: 768,
	})

	headers := map[string]string{
		"Content-Type": "application/json",
		"x-csrf-token": token,
	}

	_ = s.Post("/api/rooms", postBody, headers, func(status int, body io.Reader) error {
		if status != 200 {
			return errors.New(fails.Add("GET /api/rooms, ステータスが200ではありません: " + strconv.Itoa(status)))
		}

		res := Response{}
		err := json.NewDecoder(body).Decode(&res)
		if err != nil || res.Room == nil || res.Room.ID <= 0 {
			return errors.New(fails.Add("GET /api/rooms, レスポンス内容が正しくありません"))
		}

		score.Increment(RoomCreateScore)

		return nil
	})

	// TODO: strokeを順次postしていく

	resp, err := http.Get(aud + "?scheme=" + url.QueryEscape(s.Scheme) + "&host=" + url.QueryEscape(s.Host) + "&timeout=" + 30)
	if err != nil {
		fmt.Fprintln(os.Stderr, "failed to call audience "+aud+" :"+err.Error())
	}
	defer resp.Close()
	// TODO: audienceのresponse処理
}
